<?php

Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'UnstoppableDomainsApi.php');

class Unstoppabledomains extends RegistrarModule
{
    private static $moduleView;

    public function __construct()
    {
        $this->loadConfig(__DIR__ . DS . 'config.json');
        Loader::loadComponents($this, ['Input']);
        Language::loadLang('unstoppabledomains', null, __DIR__ . DS . 'language' . DS);
        self::$moduleView = 'components' . DS . 'modules' . DS . 'unstoppabledomains' . DS;
    }

    public function install()
    {
        $this->addCronTasks($this->getCronTasks());
    }

    public function uninstall($module_id, $last_instance)
    {
        Loader::loadModels($this, ['CronTasks']);
        $cron_tasks = $this->getCronTasks();

        if ($last_instance) {
            foreach ($cron_tasks as $task) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $this->CronTasks->deleteTask($cron_task->id, $task['task_type'], $task['dir']);
                }
            }
        }
    }

    public function upgrade($current_version)
    {
        if (version_compare($current_version, '0.4.0', '<')) {
            $this->addCronTasks($this->getCronTasks());
        }

        if (version_compare($current_version, '1.0.0', '<')) {
            // v1.0.0: Production release - no schema changes required
        }
    }

    public function cron($key)
    {
        if ($key === 'sync_domain_dates') {
            $this->syncDomainDates();
        }
    }

    private function getCronTasks()
    {
        return [
            [
                'key' => 'sync_domain_dates',
                'task_type' => 'module',
                'dir' => 'unstoppabledomains',
                'name' => Language::_('Unstoppabledomains.cron.sync_domain_dates_name', true),
                'description' => Language::_('Unstoppabledomains.cron.sync_domain_dates_desc', true),
                'type' => 'interval',
                'type_value' => 360,
                'enabled' => 1
            ]
        ];
    }

    private function addCronTasks(array $tasks)
    {
        Loader::loadModels($this, ['CronTasks']);
        foreach ($tasks as $task) {
            $task_id = $this->CronTasks->add($task);

            if (!$task_id) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $task_id = $cron_task->id;
                }
            }

            if ($task_id) {
                $task_vars = ['enabled' => $task['enabled']];
                if ($task['type'] === 'time') {
                    $task_vars['time'] = $task['type_value'];
                } else {
                    $task_vars['interval'] = $task['type_value'];
                }
                $this->CronTasks->addTaskRun($task_id, $task_vars);
            }
        }
    }

    private function syncDomainDates()
    {
        Loader::loadModels($this, ['Services']);
        Loader::loadComponents($this, ['Record']);

        $module_rows = $this->Record->from('module_rows')
            ->select(['module_rows.*'])
            ->innerJoin('modules', 'modules.id', '=', 'module_rows.module_id', false)
            ->where('modules.company_id', '=', Configure::get('Blesta.company_id'))
            ->where('modules.class', '=', 'unstoppabledomains')
            ->fetchAll();

        foreach ($module_rows as $module_row) {
            $row = $this->getModuleRow($module_row->id);
            if (!$row) {
                continue;
            }

            $mode = $this->getApiPreference($row);

            $services = $this->Record->from('services')
                ->select(['services.id'])
                ->where('services.module_row_id', '=', $row->id)
                ->where('services.status', '=', 'active')
                ->fetchAll();

            foreach ($services as $svc) {
                $service = $this->Services->get($svc->id);
                if (!$service) {
                    continue;
                }

                $domain = $this->getServiceDomain($service);
                if (empty($domain)) {
                    continue;
                }

                $info = $this->getDomainInfoForMode($domain, $row, $mode);
                if (empty($info)) {
                    continue;
                }

                $expiry = null;
                foreach (['expiresAt', 'expirationDate', 'renewsAt'] as $key) {
                    if (!empty($info[$key])) {
                        $expiry = date('Y-m-d H:i:s', strtotime($info[$key]));
                        break;
                    }
                }

                if ($expiry && !empty($service->date_renews) && $expiry !== $service->date_renews) {
                    $this->Services->edit($service->id, ['date_renews' => $expiry], true);
                }
            }
        }
    }

    public function getServiceName($service)
    {
        return $this->getServiceDomain($service);
    }

    public function getGroupName($group)
    {
        return $group->name;
    }

    public function getGroupOrderOptions()
    {
        return ['first' => Language::_('Module.!group.first', true)];
    }

    public function getModuleRowName($module_row)
    {
        return $module_row->meta->label ?? $module_row->id;
    }

    public function manageModule($module, array &$vars)
    {
        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$moduleView);
        Loader::loadHelpers($this, ['Html', 'Form', 'Widget']);
        $this->view->set('module', $module);
        return $this->view->fetch();
    }

    public function manageAddRow(array &$vars)
    {
        $this->view = new View('add_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$moduleView);
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars)) {
            $vars = [
                'label' => '',
                'api_preference' => 'reseller',
                'sandbox' => 'false',
                'reseller_base_url' => 'https://api.unstoppabledomains.com/partner/v3',
                'user_base_url' => 'https://api.unstoppabledomains.com',
                'notes' => ''
            ];
        }

        $this->view->set('vars', (object) $vars);
        return $this->view->fetch();
    }

    public function manageEditRow($module_row, array &$vars)
    {
        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$moduleView);
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars)) {
            $vars = (array) $module_row->meta;
        }

        $this->view->set('vars', (object) $vars);
        return $this->view->fetch();
    }

    public function addModuleRow(array &$vars)
    {
        $this->Input->setRules($this->getRowRules($vars));
        if (!$this->Input->validates($vars)) {
            return;
        }

        return $this->formatRowMeta($vars);
    }

    public function editModuleRow($module_row, array &$vars)
    {
        $merged = array_merge((array) $module_row->meta, $vars);
        $this->Input->setRules($this->getRowRules($merged));
        if (!$this->Input->validates($merged)) {
            return;
        }

        return $this->formatRowMeta($merged);
    }

    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();
        $vars = is_object($vars) ? $vars : (object) ($vars ?: []);
        $meta = (array) ($vars->meta ?? []);

        $api_mode = $fields->label(Language::_('Unstoppabledomains.package_fields.default_api_mode', true), 'ud_default_api_mode');
        $api_mode->attach(
            $fields->fieldSelect(
                'meta[default_api_mode]',
                $this->getApiModeOptions(true),
                $meta['default_api_mode'] ?? 'inherit',
                ['id' => 'ud_default_api_mode']
            )
        );
        $fields->setField($api_mode);

        $tld_options = $fields->label(Language::_('Unstoppabledomains.package_fields.tld_options', true));
        $tlds = $this->getTlds();
        sort($tlds);
        foreach ($tlds as $tld) {
            $tld_label = $fields->label($tld, 'ud_tld_' . trim($tld, '.'));
            $tld_options->attach(
                $fields->fieldCheckbox(
                    'meta[tlds][]',
                    $tld,
                    isset($meta['tlds']) && is_array($meta['tlds']) && in_array($tld, $meta['tlds']),
                    ['id' => 'ud_tld_' . trim($tld, '.')],
                    $tld_label
                )
            );
        }
        $fields->setField($tld_options);

        for ($i = 1; $i <= 5; $i++) {
            $ns = $fields->label(Language::_('Unstoppabledomains.package_fields.ns', true, $i), 'ud_package_ns' . $i);
            $ns->attach(
                $fields->fieldText(
                    'meta[ns][]',
                    $meta['ns'][$i - 1] ?? '',
                    ['id' => 'ud_package_ns' . $i]
                )
            );
            $fields->setField($ns);
        }

        return $fields;
    }

    public function addPackage(array $vars = null)
    {
        return parent::addPackage($vars);
    }

    public function editPackage($package, array $vars = null)
    {
        return parent::editPackage($package, $vars);
    }

    public function getAdminAddFields($package, $vars = null)
    {
        return $this->getDomainFields($package, $vars);
    }

    public function getClientAddFields($package, $vars = null)
    {
        return $this->getDomainFields($package, $vars);
    }

    public function getAdminEditFields($package, $vars = null)
    {
        return $this->getDomainFields($package, $vars, true);
    }

    public function getClientEditFields($package, $vars = null)
    {
        return $this->getDomainFields($package, $vars, true);
    }

    public function validateService($package, array $vars = null)
    {
        $vars = $vars ?: [];
        $row = $this->getModuleRow($package->module_row ?? null);
        $mode = $row ? $this->getEffectiveApiMode($row, $package, null, $vars) : 'reseller';
        return $this->validateDomainInput($vars, $mode);
    }

    public function validateServiceEdit($service, array $vars = null)
    {
        $vars = $vars ?: [];
        if (!empty($vars['domain'])) {
            $rules = [
                'domain' => [
                    'format' => [
                        'rule' => function ($value) {
                            return (bool) preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', trim((string) $value));
                        },
                        'message' => Language::_('Unstoppabledomains.!error.domain.invalid', true)
                    ]
                ]
            ];
            $this->Input->setRules($rules);
            return $this->Input->validates($vars);
        }
        return true;
    }

    public function addService($package, array $vars = null, $parent_package = null, $parent_service = null, $status = 'pending')
    {
        $vars = $vars ?: [];

        $row = $this->getModuleRow($package->module_row ?? null);
        if (!$row) {
            $this->Input->setErrors(['module_row' => ['missing' => Language::_('Unstoppabledomains.!error.module_row.missing', true)]]);
            return;
        }

        $mode = $this->getEffectiveApiMode($row, $package, null, $vars);
        if (!$this->validateDomainInput($vars, $mode)) {
            return;
        }

        $years = $this->getServiceYearsFromPricing($package, $vars['pricing_id'] ?? null);
        $meta = $this->buildServiceMeta($package, $vars, $years, $mode);

        if (($vars['use_module'] ?? 'false') !== 'true') {
            return $meta;
        }

        $result = !empty($vars['transfer']) || !empty($vars['auth_code'])
            ? $this->transferDomain($vars['domain'], $row->id, array_merge($vars, ['years' => $years, 'api_mode' => $mode]))
            : $this->registerDomain($vars['domain'], $row->id, array_merge($vars, ['years' => $years, 'api_mode' => $mode]));

        if (!$result['success']) {
            return;
        }

        return array_merge($meta, $result['meta']);
    }

    public function editService($package, $service, array $vars = [], $parent_package = null, $parent_service = null)
    {
        $current = $this->serviceFieldsToArray($service);
        $merged = array_merge($current, $vars);

        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row ?? null);
        if (!$row) {
            return null;
        }

        $mode = $this->getEffectiveApiMode($row, $package, $service, $merged);
        $domain = $this->getServiceDomain($service);

        if (($vars['use_module'] ?? 'false') === 'true') {
            if (isset($vars['renew']) && (int) $vars['renew'] > 0) {
                $this->renewDomain($domain, $row->id, ['years' => (int) $vars['renew'], 'service' => $service, 'api_mode' => $mode]);
            }

            if ($this->hasAny($vars, ['privacy', 'auto_renew', 'transfer_lock'])) {
                $this->updateDomainFlags($domain, $row, $mode, $vars, $service);
            }

            if ($this->hasContactChanges($vars)) {
                $this->setDomainContacts($domain, ['registrant' => $this->extractContactVars($merged)], $row->id);
            }
        }

        return $this->buildServiceMeta(
            $package,
            $merged,
            $current['years'] ?? $this->getServiceYearsFromPricing($package, $service->pricing_id ?? null),
            $mode
        );
    }

    public function renewService($package, $service, $parent_package = null, $parent_service = null, $years = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row ?? null);
        if (!$row) {
            return null;
        }

        $mode = $this->getEffectiveApiMode($row, $package, $service);
        $years = $years ?: $this->getServiceYearsFromPricing($package, $service->pricing_id ?? null);
        $this->renewDomain($this->getServiceDomain($service), $row->id, ['years' => $years, 'service' => $service, 'api_mode' => $mode]);
        return null;
    }

    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row ?? null);
        if ($row) {
            $domain = $this->getServiceDomain($service);
            $mode = $this->getEffectiveApiMode($row, $package, $service);
            $this->updateDomainFlags($domain, $row, $mode, ['auto_renew' => '0'], $service);
        }
        return null;
    }

    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row ?? null);
        if ($row) {
            $domain = $this->getServiceDomain($service);
            $mode = $this->getEffectiveApiMode($row, $package, $service);
            $this->updateDomainFlags($domain, $row, $mode, ['auto_renew' => '0'], $service);
        }
        return null;
    }

    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row ?? null);
        if ($row) {
            $domain = $this->getServiceDomain($service);
            $mode = $this->getEffectiveApiMode($row, $package, $service);
            $this->updateDomainFlags($domain, $row, $mode, ['auto_renew' => '1'], $service);
        }
        return null;
    }

    public function checkAvailability($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return false;
        }

        $mode = $this->getApiPreference($row);
        if ($mode === 'user') {
            $api = $this->getUserApi($row);
            if (!$api) {
                return false;
            }

            $response = $api->action('ud_domains_search', [
                'query' => strtolower(trim((string) $domain)),
                'limit' => 20,
                'offset' => 0
            ]);
            if (!$this->processApiResponse($response)) {
                return false;
            }

            foreach ((array) ($response['body']['results'] ?? []) as $result) {
                if (strtolower((string) ($result['domain'] ?? $result['name'] ?? '')) === strtolower($domain)) {
                    return $this->isSearchResultAvailable($result);
                }
            }

            return false;
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return false;
        }

        $response = $api->get('/domains', [
            'query' => strtolower(trim((string) $domain)),
            '$expand' => 'registration',
            'limit' => 20
        ]);
        if (!$this->processApiResponse($response)) {
            return false;
        }

        foreach ((array) ($response['body']['items'] ?? []) as $item) {
            if (strtolower((string) ($item['name'] ?? '')) === strtolower($domain)) {
                return $this->isResellerDomainAvailable($item);
            }
        }

        return false;
    }

    public function checkTransferAvailability($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return false;
        }

        if ($this->getApiPreference($row) === 'user') {
            $this->Input->setErrors(['transfer' => ['unsupported' => Language::_('Unstoppabledomains.!error.transfer.user_unsupported', true)]]);
            return false;
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return false;
        }

        $response = $api->post('/domains/' . rawurlencode($domain) . '/dns/transfers/inbound-eligibility', []);
        if (!$this->processApiResponse($response)) {
            return false;
        }

        $body = (array) ($response['body'] ?? []);
        if (array_key_exists('eligible', $body)) {
            return !empty($body['eligible']);
        }
        if (array_key_exists('transferable', $body)) {
            return !empty($body['transferable']);
        }

        return true;
    }

    public function registerDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return ['success' => false, 'meta' => []];
        }

        $mode = $this->resolveApiModeValue($vars['api_mode'] ?? null, $this->getApiPreference($row));
        $years = max(1, min(10, (int) ($vars['years'] ?? 1)));

        return $mode === 'user'
            ? $this->createDomainViaUserApi($row, $vars, $years)
            : $this->createDomainViaResellerApi($row, $vars, $years);
    }

    public function transferDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return ['success' => false, 'meta' => []];
        }

        $mode = $this->resolveApiModeValue($vars['api_mode'] ?? null, $this->getApiPreference($row));
        if ($mode === 'user') {
            $this->Input->setErrors(['transfer' => ['unsupported' => Language::_('Unstoppabledomains.!error.transfer.user_unsupported', true)]]);
            return ['success' => false, 'meta' => []];
        }

        return $this->createDomainViaResellerApi(
            $row,
            array_merge($vars, ['domain' => $domain, 'transfer' => '1']),
            max(1, min(10, (int) ($vars['years'] ?? 1)))
        );
    }

    public function renewDomain($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return false;
        }

        $mode = $this->resolveApiModeValue($vars['api_mode'] ?? null, $this->getApiPreference($row));
        $years = max(1, min(10, (int) ($vars['years'] ?? 1)));

        if ($mode === 'user') {
            $api = $this->getUserApi($row);
            if (!$api) {
                return false;
            }

            $added = $api->action('ud_cart_add_domain_renewal', [
                'domains' => [[
                    'name' => $domain,
                    'quantity' => $years
                ]]
            ]);
            if (!$this->processApiResponse($added)) {
                return false;
            }

            $checkoutPayload = ['useAccountBalance' => true];
            if (!empty($vars['payment_method_id'])) {
                $checkoutPayload['paymentMethodId'] = (string) $vars['payment_method_id'];
            } elseif (!empty($vars['service'])) {
                $service_payment_method = $this->getServiceMetaValue($vars['service'], 'user_payment_method_id');
                if (!empty($service_payment_method)) {
                    $checkoutPayload['paymentMethodId'] = $service_payment_method;
                }
            }

            return $this->processApiResponse($api->action('ud_cart_checkout', $checkoutPayload));
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return false;
        }

        return $this->processApiResponse(
            $api->post('/domains/' . rawurlencode($domain) . '/renewals', ['period' => $years], ['$preview' => 'false'])
        );
    }

    public function getDomainInfo($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return [];
        }
        return $this->getDomainInfoForMode($domain, $row, $this->getApiPreference($row));
    }

    public function getRegistrationDate($service, $format = 'Y-m-d H:i:s')
    {
        $domain = $this->getServiceDomain($service);
        $info = $this->getDomainInfo($domain, $service->module_row_id ?? null);

        foreach (['createdAt', 'registeredAt', 'purchaseDate', 'purchasedAt'] as $key) {
            if (!empty($info[$key])) {
                return date($format, strtotime($info[$key]));
            }
        }

        return parent::getRegistrationDate($service, $format);
    }

    public function getExpirationDate($service, $format = 'Y-m-d H:i:s')
    {
        $domain = $this->getServiceDomain($service);
        $info = $this->getDomainInfo($domain, $service->module_row_id ?? null);

        foreach (['expiresAt', 'expirationDate', 'renewsAt'] as $key) {
            if (!empty($info[$key])) {
                return date($format, strtotime($info[$key]));
            }
        }

        return parent::getExpirationDate($service, $format);
    }

    public function getDomainContacts($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return [];
        }
        return $this->getDomainContactsForMode($domain, $row, $this->getApiPreference($row));
    }

    public function setDomainContacts($domain, array $vars = [], $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return false;
        }

        if ($this->getApiPreference($row) === 'user') {
            $this->Input->setErrors(['contacts' => ['unsupported' => Language::_('Unstoppabledomains.!error.contacts.user_unsupported', true)]]);
            return false;
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return false;
        }

        $payload = [];
        $roles = [
            'registrant' => 'owner',
            'owner' => 'owner',
            'admin' => 'admin',
            'technical' => 'tech',
            'tech' => 'tech',
            'billing' => 'billing'
        ];

        foreach ($roles as $inputRole => $apiRole) {
            if (!empty($vars[$inputRole]) && is_array($vars[$inputRole])) {
                $payload[$apiRole] = $this->buildContactPayload($vars[$inputRole]);
            }
        }

        if (empty($payload)) {
            return true;
        }

        return $this->processApiResponse(
            $api->patch('/domains/' . rawurlencode($domain) . '/dns/contacts', $payload, ['$preview' => 'false'])
        );
    }

    public function getDomainIsLocked($domain, $module_row_id = null)
    {
        $flags = $this->getDomainFlags($domain, $module_row_id);
        if (empty($flags)) {
            return false;
        }

        return !$this->extractFlagEnabled($flags, 'DNS_TRANSFER_OUT');
    }

    public function lockDomain($domain, $module_row_id = null)
    {
        return $this->setTransferLockState($domain, $module_row_id, true);
    }

    public function unlockDomain($domain, $module_row_id = null)
    {
        return $this->setTransferLockState($domain, $module_row_id, false);
    }

    public function getServiceDomain($service)
    {
        foreach ((array) $service->fields as $field) {
            if ($field->key === 'domain') {
                return $field->value;
            }
        }

        return parent::getServiceDomain($service);
    }

    public function getAdminServiceInfo($service, $package)
    {
        return $this->renderServiceInfo($service, $package, false);
    }

    public function getClientServiceInfo($service, $package)
    {
        return $this->renderServiceInfo($service, $package, true);
    }

    public function getDomainNameServers($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return [];
        }
        return $this->getDomainNameServersForMode($domain, $row, $this->getApiPreference($row));
    }

    public function setDomainNameservers($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return false;
        }
        return $this->setDomainNameserversForMode($domain, $row, $this->getApiPreference($row), $vars);
    }

    public function getClientTabs($package)
    {
        return [
            'tabClientNameservers' => ['name' => Language::_('Unstoppabledomains.tab.client_nameservers', true)],
            'tabClientDns' => ['name' => Language::_('Unstoppabledomains.tab.client_dns', true)],
            'tabClientDnssec' => ['name' => Language::_('Unstoppabledomains.tab.client_dnssec', true)],
            'tabClientSettings' => ['name' => Language::_('Unstoppabledomains.tab.client_settings', true)]
        ];
    }

    public function getAdminTabs($package)
    {
        return [
            'tabAdminDnssec' => Language::_('Unstoppabledomains.tab.admin_dnssec', true),
            'tabAdminActions' => Language::_('Unstoppabledomains.tab.admin_actions', true)
        ];
    }

    public function tabClientNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($service->module_row_id ?? null);
        $mode = $row ? $this->getEffectiveApiMode($row, $package, $service) : 'reseller';
        if ($row && !empty($post) && !empty($post['save_nameservers'])) {
            $this->setDomainNameserversForMode($domain, $row, $mode, $post);
        }

        $this->view = new View('tab_client_nameservers', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$moduleView);
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('domain', $domain);
        $this->view->set('nameservers', $this->extractNameserverUrls($this->getDomainNameServersForMode($domain, $row, $mode)));
        return $this->view->fetch();
    }

    public function tabClientDns($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($service->module_row_id ?? null);
        $mode = $row ? $this->getEffectiveApiMode($row, $package, $service) : 'reseller';
        $records = [];

        if ($row && !empty($post) && !empty($post['delete_record']) && !empty($post['record_id'])) {
            if ($mode === 'user') {
                $api = $this->getUserApi($row);
                if ($api) {
                    $this->processApiResponse($api->action('ud_dns_record_remove', [
                        'records' => [[
                            'domain' => $domain,
                            'recordId' => (string) $post['record_id']
                        ]]
                    ]));
                }
            } else {
                $api = $this->getResellerApi($row);
                if ($api) {
                    $this->processApiResponse(
                        $api->delete('/domains/' . rawurlencode($domain) . '/dns/records/' . rawurlencode((string) $post['record_id']), [], ['$preview' => 'false'])
                    );
                }
            }
        }

        if ($row && !empty($post) && !empty($post['add_dns'])) {
            $ttl = max(60, min(86400, (int) ($post['ttl'] ?? 300)));
            $values = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($post['values'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))));
            if ($mode === 'user') {
                $api = $this->getUserApi($row);
                if ($api) {
                    $this->processApiResponse($api->action('ud_dns_record_add', [
                        'records' => [[
                            'domain' => $domain,
                            'subName' => trim((string) ($post['subName'] ?? '@')),
                            'type' => strtoupper(trim((string) ($post['type'] ?? 'A'))),
                            'values' => $values,
                            'ttl' => $ttl
                        ]],
                        'upsertMode' => 'append'
                    ]));
                }
            } else {
                $api = $this->getResellerApi($row);
                if ($api) {
                    $payload = [
                        'subName' => trim((string) ($post['subName'] ?? '')),
                        'type' => strtoupper(trim((string) ($post['type'] ?? 'A'))),
                        'ttl' => $ttl
                    ];
                    if (count($values) <= 1) {
                        $payload['value'] = $values[0] ?? '';
                    } else {
                        $payload['values'] = $values;
                    }
                    $this->processApiResponse(
                        $api->post('/domains/' . rawurlencode($domain) . '/dns/records', $payload, ['$preview' => 'false', '$upsert' => 'APPEND'])
                    );
                }
            }
        }

        if ($row) {
            $records = $this->getDomainDnsRecords($domain, $row, $mode);
        }

        $this->view = new View('tab_client_dns', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$moduleView);
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('domain', $domain);
        $this->view->set('records', $records);
        return $this->view->fetch();
    }

    public function tabClientSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($service->module_row_id ?? null);
        $mode = $row ? $this->getEffectiveApiMode($row, $package, $service) : 'reseller';
        $flags = [];

        if ($row && !empty($post) && !empty($post['save_settings'])) {
            $this->updateDomainFlags($domain, $row, $mode, $post, $service);
        }

        if ($row) {
            $flags = $this->getDomainFlagsForMode($domain, $row, $mode);
        }

        $this->view = new View('tab_client_settings', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$moduleView);
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('domain', $domain);
        $this->view->set('mode', $mode);
        $this->view->set('flags', $flags);
        return $this->view->fetch();
    }

    public function tabAdminActions($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($service->module_row_id ?? null);
        $mode = $row ? $this->getEffectiveApiMode($row, $package, $service) : 'reseller';
        $operation = null;
        $eppCode = null;
        $domainInfo = [];
        $contacts = [];
        $isLocked = null;

        if ($row && !empty($post)) {
            if (!empty($post['refresh_remote'])) {
                $domainInfo = $this->getDomainInfoForMode($domain, $row, $mode);
                $contacts = $this->getDomainContactsForMode($domain, $row, $mode);
                $isLocked = !$this->extractFlagEnabled($this->getDomainFlagsForMode($domain, $row, $mode), 'DNS_TRANSFER_OUT');
                if ($mode === 'user') {
                    $operation = $domainInfo['pendingOperations'][0] ?? null;
                } else {
                    $opId = $this->getServiceMetaValue($service, 'last_operation_id');
                    if (!empty($opId)) {
                        $api = $this->getResellerApi($row);
                        if ($api) {
                            $response = $api->get('/operations/' . rawurlencode($opId));
                            if ($this->processApiResponse($response)) {
                                $operation = $response['body'];
                            }
                        }
                    }
                }
            }

            if (!empty($post['get_epp']) && $mode === 'reseller') {
                $api = $this->getResellerApi($row);
                if ($api) {
                    $response = $api->get('/domains/' . rawurlencode($domain) . '/dns/authorization-code');
                    if ($this->processApiResponse($response)) {
                        $eppCode = $response['body']['authorizationCode'] ?? $response['body']['code'] ?? null;
                    }
                }
            }
        }

        $this->view = new View('tab_admin_actions', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$moduleView);
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('domain', $domain);
        $this->view->set('service', $service);
        $this->view->set('mode', $mode);
        $this->view->set('operation', $operation);
        $this->view->set('eppCode', $eppCode);
        $this->view->set('domainInfo', $domainInfo);
        $this->view->set('contacts', $contacts);
        $this->view->set('isLocked', $isLocked);
        return $this->view->fetch();
    }

    public function tabClientDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnssec('tab_client_dnssec', $package, $service, $get, $post, $files);
    }

    public function tabAdminDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnssec('tab_admin_dnssec', $package, $service, $get, $post, $files);
    }

    private function manageDnssec($view, $package, $service, $get, $post, $files)
    {
        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($service->module_row_id ?? null);
        $mode = $row ? $this->getEffectiveApiMode($row, $package, $service) : 'reseller';

        $dnssecStatus = null;
        $dnssecDetails = [];
        $resellerOnly = ($mode === 'user');

        if (!$resellerOnly && $row) {
            $api = $this->getResellerApi($row);

            if ($api && !empty($post)) {
                if (!empty($post['enable_dnssec'])) {
                    $response = $api->post(
                        '/domains/' . rawurlencode($domain) . '/dns/security',
                        [],
                        ['$preview' => 'false']
                    );
                    $this->processApiResponse($response);
                }

                if (!empty($post['disable_dnssec'])) {
                    $response = $api->delete(
                        '/domains/' . rawurlencode($domain) . '/dns/security',
                        [],
                        ['$preview' => 'false']
                    );
                    $this->processApiResponse($response);
                }
            }

            if ($api) {
                $response = $api->get('/domains/' . rawurlencode($domain) . '/dns/security');
                if ($this->processApiResponse($response)) {
                    $body = (array) ($response['body'] ?? []);
                    $dnssecStatus = $this->extractDnssecEnabled($body);
                    $dnssecDetails = $body;
                }
            }
        }

        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$moduleView);
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('domain', $domain);
        $this->view->set('mode', $mode);
        $this->view->set('reseller_only', $resellerOnly);
        $this->view->set('dnssec_status', $dnssecStatus);
        $this->view->set('dnssec_details', $dnssecDetails);
        return $this->view->fetch();
    }

    private function extractDnssecEnabled(array $body)
    {
        if (array_key_exists('enabled', $body)) {
            return !empty($body['enabled']);
        }
        $status = strtolower((string) ($body['status'] ?? ''));
        if ($status === 'enabled' || $status === 'active') {
            return true;
        }
        if ($status === 'disabled' || $status === 'inactive') {
            return false;
        }
        return null;
    }

    public function getTlds($module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            $rows = $this->getModuleRows();
            $row = $rows[0] ?? null;
        }
        if (!$row) {
            return [];
        }

        if ($this->getApiPreference($row) === 'user') {
            $api = $this->getUserApi($row);
            if (!$api) {
                return [];
            }

            $response = $api->action('ud_tld_list', []);
            if (!$this->processApiResponse($response)) {
                return [];
            }

            $items = (array) ($response['body']['tlds'] ?? []);
            $tlds = [];
            foreach ($items as $item) {
                if (is_string($item) && $item !== '') {
                    $tlds[] = '.' . ltrim($item, '.');
                }
            }
            return array_values(array_unique($tlds));
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return [];
        }

        $response = $api->get('/tlds');
        if (!$this->processApiResponse($response)) {
            return [];
        }

        $tlds = [];
        foreach ((array) ($response['body']['items'] ?? []) as $item) {
            if (!empty($item['tld'])) {
                $tlds[] = '.' . ltrim($item['tld'], '.');
            }
        }

        return array_values(array_unique($tlds));
    }

    public function getTldPricing($module_row_id = null)
    {
        return $this->getFilteredTldPricing($module_row_id);
    }

    public function getFilteredTldPricing($module_row_id = null, $filters = [])
    {
        Loader::loadModels($this, ['Currencies']);

        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return [];
        }

        if ($this->getApiPreference($row) === 'user') {
            return [];
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return [];
        }

        $response = $api->get('/pricing/dns/tlds');
        if (!$this->processApiResponse($response)) {
            return [];
        }

        $currencies = $this->Currencies->getAll(Configure::get('Blesta.company_id'));
        $pricing = [];

        foreach ((array) ($response['body']['items'] ?? []) as $item) {
            $tld = '.' . ltrim((string) ($item['tld'] ?? ''), '.');
            if ($tld === '.') {
                continue;
            }
            if (!empty($filters['tlds']) && !in_array($tld, $filters['tlds'])) {
                continue;
            }

            $register = $this->extractAmount($item, ['registrationPrice', 'registerPrice', 'registration', 'register']);
            $renew = $this->extractAmount($item, ['renewalPrice', 'renewPrice', 'renewal', 'renew']);
            $transfer = $this->extractAmount($item, ['transferPrice', 'transfer']);
            $currency = strtoupper((string) ($item['currency'] ?? 'USD'));

            foreach ($currencies as $companyCurrency) {
                if (!empty($filters['currencies']) && !in_array($companyCurrency->code, $filters['currencies'])) {
                    continue;
                }

                $pricing[$tld][$companyCurrency->code] = [];
                foreach (range(1, 10) as $years) {
                    if (!empty($filters['terms']) && !in_array($years, $filters['terms'])) {
                        continue;
                    }

                    $pricing[$tld][$companyCurrency->code][$years] = [
                        'register' => $this->Currencies->convert(
                            $register * $years,
                            $currency,
                            $companyCurrency->code,
                            Configure::get('Blesta.company_id')
                        ),
                        'transfer' => $this->Currencies->convert(
                            $transfer,
                            $currency,
                            $companyCurrency->code,
                            Configure::get('Blesta.company_id')
                        ),
                        'renew' => $this->Currencies->convert(
                            $renew * $years,
                            $currency,
                            $companyCurrency->code,
                            Configure::get('Blesta.company_id')
                        )
                    ];
                }
            }
        }

        return $pricing;
    }

    private function getDomainFields($package, $vars = null, $editing = false)
    {
        Loader::loadHelpers($this, ['Form', 'Html']);

        $fields = new ModuleFields();
        $vars = is_object($vars) ? $vars : (object) ($vars ?: []);

        $api_mode = $fields->label(Language::_('Unstoppabledomains.service_field.api_mode', true), 'api_mode');
        $api_mode->attach(
            $fields->fieldSelect(
                'api_mode',
                $this->getApiModeOptions(true),
                $vars->api_mode ?? (($package->meta->default_api_mode ?? 'inherit') ?: 'inherit'),
                ['id' => 'api_mode']
            )
        );
        $fields->setField($api_mode);

        foreach ([
            'domain', 'auth_code', 'registrant_first_name', 'registrant_last_name', 'registrant_organization',
            'email', 'phone', 'address1', 'city', 'state', 'zip', 'country', 'ns1', 'ns2', 'ns3', 'ns4', 'ns5',
            'user_payment_method_id'
        ] as $name) {
            $value = $vars->{$name} ?? '';
            if (!$editing && strpos($name, 'ns') === 0 && empty($value)) {
                $ns_index = (int) substr($name, 2) - 1;
                $value = $package->meta->ns[$ns_index] ?? '';
            }
            $label = $fields->label(Language::_('Unstoppabledomains.service_field.' . $name, true), $name);
            $label->attach($fields->fieldText($name, $value, ['id' => $name]));
            $fields->setField($label);
        }

        foreach (['transfer', 'privacy', 'auto_renew', 'transfer_lock', 'reset_default_nameservers'] as $name) {
            $label = $fields->label(Language::_('Unstoppabledomains.service_field.' . $name, true), $name);
            $label->attach($fields->fieldCheckbox($name, '1', !empty($vars->{$name}), ['id' => $name]));
            $fields->setField($label);
        }

        return $fields;
    }

    private function getRowRules(array $vars)
    {
        return [
            'label' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Unstoppabledomains.!error.label.empty', true)
                ]
            ],
            'reseller_token' => [
                'required' => [
                    'rule' => function ($value) use ($vars) {
                        return (($vars['api_preference'] ?? 'reseller') !== 'reseller') || !empty($value);
                    },
                    'message' => Language::_('Unstoppabledomains.!error.reseller_token.empty', true)
                ]
            ],
            'user_api_key' => [
                'required' => [
                    'rule' => function ($value) use ($vars) {
                        return (($vars['api_preference'] ?? 'reseller') !== 'user') || !empty($value);
                    },
                    'message' => Language::_('Unstoppabledomains.!error.user_api_key.empty', true)
                ]
            ],
            'reseller_base_url' => [
                'valid' => [
                    'rule' => [$this, 'validateBaseUrl'],
                    'message' => Language::_('Unstoppabledomains.!error.base_url.invalid', true)
                ]
            ],
            'user_base_url' => [
                'valid' => [
                    'rule' => [$this, 'validateBaseUrl'],
                    'message' => Language::_('Unstoppabledomains.!error.base_url.invalid', true)
                ]
            ]
        ];
    }

    private function formatRowMeta(array $vars)
    {
        if (empty($vars['sandbox'])) {
            $vars['sandbox'] = 'false';
        }

        $metaFields = ['label', 'api_preference', 'sandbox', 'reseller_token', 'user_api_key', 'reseller_base_url', 'user_base_url', 'notes'];
        $encrypted = ['reseller_token', 'user_api_key'];
        $meta = [];

        foreach ($metaFields as $field) {
            $meta[] = [
                'key' => $field,
                'value' => $vars[$field] ?? '',
                'encrypted' => in_array($field, $encrypted) ? 1 : 0
            ];
        }

        return $meta;
    }

    private function validateDomainInput(array $vars, $mode)
    {
        $rules = [
            'domain' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Unstoppabledomains.!error.domain.empty', true)
                ],
                'format' => [
                    'rule' => function ($value) {
                        return (bool) preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', trim((string) $value));
                    },
                    'message' => Language::_('Unstoppabledomains.!error.domain.invalid', true)
                ]
            ]
        ];

        if (!empty($vars['transfer']) || !empty($vars['auth_code'])) {
            $rules['auth_code'] = [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Unstoppabledomains.!error.auth_code.empty', true)
                ]
            ];
            if ($mode === 'user') {
                $this->Input->setErrors(['transfer' => ['unsupported' => Language::_('Unstoppabledomains.!error.transfer.user_unsupported', true)]]);
                return false;
            }
        }

        $this->Input->setRules($rules);
        return $this->Input->validates($vars);
    }

    private function buildServiceMeta($package, array $vars, $years, $mode)
    {
        $keys = [
            'api_mode', 'domain', 'auth_code', 'registrant_first_name', 'registrant_last_name', 'registrant_organization',
            'email', 'phone', 'address1', 'city', 'state', 'zip', 'country', 'ns1', 'ns2', 'ns3', 'ns4', 'ns5',
            'privacy', 'auto_renew', 'transfer', 'transfer_lock', 'user_payment_method_id'
        ];
        $meta = [];
        $vars['api_mode'] = $mode;

        $encrypted_keys = ['auth_code'];
        foreach ($keys as $key) {
            $value = $vars[$key] ?? '';
            if (strpos($key, 'ns') === 0 && empty($value)) {
                $ns_index = (int) substr($key, 2) - 1;
                $value = $package->meta->ns[$ns_index] ?? '';
            }
            $meta[] = ['key' => $key, 'value' => $value, 'encrypted' => in_array($key, $encrypted_keys) ? 1 : 0];
        }

        $meta[] = ['key' => 'years', 'value' => (string) $years, 'encrypted' => 0];
        return $meta;
    }

    private function getServiceYearsFromPricing($package, $pricingId)
    {
        foreach ((array) $package->pricing as $pricing) {
            if ($pricing->id == $pricingId) {
                return (int) $pricing->term;
            }
        }
        return 1;
    }

    private function createDomainViaResellerApi($row, array $vars, $years)
    {
        $api = $this->getResellerApi($row);
        if (!$api) {
            $this->Input->setErrors(['api' => ['missing' => Language::_('Unstoppabledomains.!error.api.missing', true)]]);
            return ['success' => false, 'meta' => []];
        }

        $response = $api->post('/domains', $this->buildRegistrationPayload($vars, $years), ['$preview' => 'false']);
        if (!$this->processApiResponse($response)) {
            return ['success' => false, 'meta' => []];
        }

        $meta = [];
        $operation = $response['body']['operation'] ?? $response['body'] ?? null;
        if (is_array($operation) && !empty($operation['id'])) {
            $meta[] = ['key' => 'last_operation_id', 'value' => $operation['id'], 'encrypted' => 0];
            $meta[] = ['key' => 'last_operation_status', 'value' => $operation['status'] ?? '', 'encrypted' => 0];
        }

        return ['success' => true, 'meta' => $meta];
    }

    private function createDomainViaUserApi($row, array $vars, $years)
    {
        $api = $this->getUserApi($row);
        if (!$api) {
            $this->Input->setErrors(['api' => ['missing' => Language::_('Unstoppabledomains.!error.api.missing', true)]]);
            return ['success' => false, 'meta' => []];
        }

        $contactId = $this->getOrCreateUserContactId($api, $vars);
        if (!$contactId) {
            return ['success' => false, 'meta' => []];
        }

        $added = $api->action('ud_cart_add_domain_registration', [
            'domains' => [[
                'name' => strtolower(trim((string) $vars['domain'])),
                'quantity' => max(1, min(10, (int) $years))
            ]]
        ]);
        if (!$this->processApiResponse($added)) {
            return ['success' => false, 'meta' => []];
        }

        $checkoutPayload = [
            'useAccountBalance' => true,
            'contactId' => $contactId
        ];
        if (!empty($vars['user_payment_method_id'])) {
            $checkoutPayload['paymentMethodId'] = trim((string) $vars['user_payment_method_id']);
        }

        $checkout = $api->action('ud_cart_checkout', $checkoutPayload);
        if (!$this->processApiResponse($checkout)) {
            return ['success' => false, 'meta' => []];
        }

        $summary = $checkout['body']['summary'] ?? [];
        $meta = [
            ['key' => 'user_contact_id', 'value' => $contactId, 'encrypted' => 0],
            ['key' => 'user_order_id', 'value' => (string) ($checkout['body']['orderId'] ?? ''), 'encrypted' => 0],
            ['key' => 'user_payment_id', 'value' => (string) ($checkout['body']['paymentId'] ?? ''), 'encrypted' => 0],
            ['key' => 'last_operation_status', 'value' => !empty($checkout['body']['success']) ? 'COMPLETED' : 'UNKNOWN', 'encrypted' => 0],
            ['key' => 'checkout_total', 'value' => (string) ($summary['totalChargedFormatted'] ?? ''), 'encrypted' => 0]
        ];

        return ['success' => true, 'meta' => $meta];
    }

    private function buildRegistrationPayload(array $vars, $years)
    {
        $contact_payload = $this->buildContactPayload($vars);
        $payload = [
            'name' => strtolower(trim((string) $vars['domain'])),
            'owner' => [
                'type' => 'MANAGED',
                'contact' => $contact_payload
            ],
            'dns' => [
                'period' => max(1, min(10, (int) $years)),
                'contacts' => [
                    'admin' => $contact_payload,
                    'tech' => $contact_payload,
                    'billing' => $contact_payload
                ]
            ]
        ];

        if (!empty($vars['auth_code'])) {
            $payload['dns']['authorizationCode'] = trim((string) $vars['auth_code']);
        }

        $nameservers = [];
        foreach (['ns1', 'ns2', 'ns3', 'ns4', 'ns5'] as $nsField) {
            if (!empty($vars[$nsField])) {
                $nameservers[] = trim((string) $vars[$nsField]);
            }
        }
        if (!empty($nameservers)) {
            $payload['dns']['nameservers'] = array_values($nameservers);
        }

        return $payload;
    }

    private function buildContactPayload(array $vars)
    {
        $phone = $this->parsePhone($vars['phone'] ?? '');
        return [
            'firstName' => $vars['registrant_first_name'] ?? 'Domain',
            'lastName' => $vars['registrant_last_name'] ?? 'Owner',
            'organization' => $vars['registrant_organization'] ?? '',
            'countryCode' => strtoupper(trim((string) ($vars['country'] ?? 'US'))),
            'street' => $vars['address1'] ?? 'N/A',
            'city' => $vars['city'] ?? 'N/A',
            'postalCode' => $vars['zip'] ?? '00000',
            'stateProvince' => $vars['state'] ?? 'N/A',
            'phone' => $phone,
            'email' => $vars['email'] ?? ''
        ];
    }

    private function parsePhone($phone)
    {
        $raw = trim((string) $phone);
        $raw = preg_replace('/[^\d\+]/', '', $raw);
        $digits = preg_replace('/\D/', '', $raw);

        if (strpos($raw, '+') === 0 && preg_match('/^\+(\d{1,4})(\d{6,14})$/', $raw, $m)) {
            return ['dialingPrefix' => '+' . $m[1], 'number' => $m[2]];
        }

        if (strlen($digits) > 10) {
            return [
                'dialingPrefix' => '+' . substr($digits, 0, strlen($digits) - 10),
                'number' => substr($digits, -10)
            ];
        }

        return ['dialingPrefix' => '+1', 'number' => $digits ?: '0000000000'];
    }

    public function validateBaseUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }

        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if (strtolower((string) $parts['scheme']) !== 'https') {
            return false;
        }

        if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['fragment'])) {
            return false;
        }

        return true;
    }

    private function getApiPreference($row)
    {
        return in_array($row->meta->api_preference ?? 'reseller', ['reseller', 'user']) ? $row->meta->api_preference : 'reseller';
    }

    private function getEffectiveApiMode($row, $package = null, $service = null, array $vars = [])
    {
        if (!empty($vars['api_mode']) && $vars['api_mode'] !== 'inherit') {
            return $this->resolveApiModeValue($vars['api_mode'], $this->getApiPreference($row));
        }

        if ($service) {
            $service_mode = $this->getServiceMetaValue($service, 'api_mode');
            if (!empty($service_mode) && $service_mode !== 'inherit') {
                return $this->resolveApiModeValue($service_mode, $this->getApiPreference($row));
            }
        }

        if ($package && !empty($package->meta->default_api_mode) && $package->meta->default_api_mode !== 'inherit') {
            return $this->resolveApiModeValue($package->meta->default_api_mode, $this->getApiPreference($row));
        }

        return $this->getApiPreference($row);
    }

    private function resolveApiModeValue($value, $default = 'reseller')
    {
        return in_array($value, ['reseller', 'user']) ? $value : $default;
    }

    private function getApiModeOptions($include_inherit = false)
    {
        $options = [
            'reseller' => Language::_('Unstoppabledomains.row_meta.api_preference.reseller', true),
            'user' => Language::_('Unstoppabledomains.row_meta.api_preference.user', true)
        ];

        if ($include_inherit) {
            return ['inherit' => Language::_('Unstoppabledomains.api_mode.inherit', true)] + $options;
        }

        return $options;
    }

    private function getResellerApi($row)
    {
        if (!$row || empty($row->meta->reseller_token)) {
            return null;
        }

        $baseUrl = !empty($row->meta->reseller_base_url)
            ? $row->meta->reseller_base_url
            : (($row->meta->sandbox ?? 'false') === 'true'
                ? 'https://api.ud-sandbox.com/partner/v3'
                : 'https://api.unstoppabledomains.com/partner/v3');

        return new UnstoppableDomainsApi($baseUrl, $row->meta->reseller_token);
    }

    private function getUserApi($row)
    {
        if (!$row || empty($row->meta->user_api_key)) {
            return null;
        }

        $baseUrl = !empty($row->meta->user_base_url) ? $row->meta->user_base_url : 'https://api.unstoppabledomains.com';
        return new UnstoppableDomainsApi($baseUrl, $row->meta->user_api_key);
    }

    private function getOrCreateUserContactId(UnstoppableDomainsApi $api, array $vars)
    {
        $preferred_email = strtolower(trim((string) ($vars['email'] ?? '')));
        $list = $api->action('ud_contacts_list', ['includeDisabled' => false]);
        if ($this->isSuccessful($list)) {
            foreach ((array) ($list['body']['contacts'] ?? []) as $contact) {
                if (($contact['status'] ?? '') === 'active' || ($contact['status'] ?? '') === 'verified') {
                    $email = strtolower(trim((string) ($contact['email'] ?? '')));
                    if ($preferred_email === '' || $email === $preferred_email) {
                        return $contact['id'];
                    }
                }
            }
        }

        $create = $api->action('ud_contact_create', $this->buildContactPayload($vars));
        if (!$this->processApiResponse($create)) {
            return null;
        }

        $createdList = $api->action('ud_contacts_list', ['includeDisabled' => false]);
        if ($this->isSuccessful($createdList)) {
            foreach ((array) ($createdList['body']['contacts'] ?? []) as $contact) {
                if (($contact['status'] ?? '') === 'active' || ($contact['status'] ?? '') === 'verified' || ($contact['status'] ?? '') === 'draft') {
                    $email = strtolower(trim((string) ($contact['email'] ?? '')));
                    if ($preferred_email === '' || $email === $preferred_email) {
                        return $contact['id'];
                    }
                }
            }
        }

        $this->Input->setErrors(['api' => ['response' => Language::_('Unstoppabledomains.!error.contact.not_ready', true)]]);
        return null;
    }

    private function isSuccessful(array $response)
    {
        return ($response['status'] >= 200 && $response['status'] < 300);
    }

    private function processApiResponse(array $response)
    {
        $url = ($response['method'] ?? 'GET') . ' ' . ($response['url'] ?? 'unknown');
        $success = $this->isSuccessful($response);

        // Log the API request input
        $this->log($url, json_encode($response['request_body'] ?? []), 'input', true);
        // Log the API response output
        $this->log($url, json_encode($response['body'] ?? $response['raw'] ?? ''), 'output', $success);

        if ($success) {
            if (!empty($response['body']['success']) && $response['body']['success'] === false) {
                $message = strip_tags((string) ($response['body']['message'] ?? '')) ?: Language::_('Unstoppabledomains.!error.api.unknown', true);
                $this->Input->setErrors(['api' => ['response' => $message]]);
                return false;
            }
            if (!empty($response['body']['failureCount']) && (int) $response['body']['failureCount'] > 0) {
                foreach ((array) ($response['body']['results'] ?? []) as $result) {
                    if (empty($result['success']) && !empty($result['error'])) {
                        $this->Input->setErrors(['api' => ['response' => strip_tags((string) $result['error'])]]);
                        return false;
                    }
                }
            }
            return true;
        }

        $message = Language::_('Unstoppabledomains.!error.api.unknown', true);
        if (!empty($response['curl_error'])) {
            $message = $response['curl_error'];
        } elseif (!empty($response['body']['message'])) {
            $message = strip_tags((string) $response['body']['message']);
        } elseif (!empty($response['body']['error'])) {
            $message = strip_tags(is_string($response['body']['error']) ? $response['body']['error'] : json_encode($response['body']['error']));
        }

        $this->Input->setErrors(['api' => ['response' => $message]]);
        return false;
    }

    private function getServiceMetaValue($service, $key)
    {
        foreach ((array) $service->fields as $field) {
            if ($field->key === $key) {
                return $field->value;
            }
        }
        return null;
    }

    private function serviceFieldsToArray($service)
    {
        $fields = [];
        foreach ((array) $service->fields as $field) {
            $fields[$field->key] = $field->value;
        }
        return $fields;
    }

    private function renderServiceInfo($service, $package, $client)
    {
        $domain = $this->getServiceDomain($service);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row ?? null);
        $mode = $row ? $this->getEffectiveApiMode($row, $package, $service) : 'reseller';
        $info = [
            Language::_('Unstoppabledomains.service_info.domain', true) => $domain,
            Language::_('Unstoppabledomains.service_info.mode', true) => ucfirst($mode),
            Language::_('Unstoppabledomains.service_info.operation', true) => $this->getServiceMetaValue($service, 'last_operation_status') ?: Language::_('Unstoppabledomains.status.none', true)
        ];

        if (!$client) {
            $info[Language::_('Unstoppabledomains.service_info.order_id', true)] = $this->getServiceMetaValue($service, 'user_order_id') ?: '-';
        }

        $html = '<table class="table table-bordered table-striped"><tbody>';
        foreach ($info as $label => $value) {
            $html .= '<tr><th style="width:180px;">' . $label . '</th><td>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    private function normalizeNameservers($nameservers)
    {
        $normalized = [];
        foreach ((array) $nameservers as $nameserver) {
            if (is_array($nameserver)) {
                $url = $nameserver['url'] ?? $nameserver['hostname'] ?? $nameserver['name'] ?? null;
                if ($url) {
                    $normalized[] = [
                        'url' => $url,
                        'ips' => (array) ($nameserver['ips'] ?? [])
                    ];
                }
            } elseif (is_string($nameserver) && $nameserver !== '') {
                $normalized[] = ['url' => $nameserver, 'ips' => []];
            }
        }
        return $normalized;
    }

    private function extractNameserverUrls(array $nameservers)
    {
        $urls = [];
        foreach ($nameservers as $nameserver) {
            $url = is_array($nameserver) ? ($nameserver['url'] ?? '') : (string) $nameserver;
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    private function getDomainDnsRecords($domain, $row, $mode)
    {
        $records = [];

        if ($mode === 'user') {
            $api = $this->getUserApi($row);
            if ($api) {
                $response = $api->action('ud_dns_records_list', ['domain' => $domain]);
                if ($this->processApiResponse($response)) {
                    $records = (array) ($response['body']['records'] ?? []);
                }
            }
        } else {
            $api = $this->getResellerApi($row);
            if ($api) {
                $response = $api->get('/domains/' . rawurlencode($domain) . '/dns/records');
                if ($this->processApiResponse($response)) {
                    $records = (array) ($response['body']['items'] ?? $response['body']['records'] ?? []);
                }
            }
        }

        return $this->normalizeDnsRecords($records);
    }

    private function normalizeDnsRecords(array $records)
    {
        $normalized = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $values = [];
            if (!empty($record['values']) && is_array($record['values'])) {
                $values = $record['values'];
            } elseif (array_key_exists('value', $record)) {
                $values = [(string) $record['value']];
            }

            $normalized[] = [
                'id' => $record['id'] ?? $record['recordId'] ?? '',
                'subName' => $record['subName'] ?? $record['name'] ?? '@',
                'type' => $record['type'] ?? '',
                'values' => $values,
                'ttl' => $record['ttl'] ?? '',
                'readonly' => !empty($record['readonly'])
            ];
        }
        return $normalized;
    }

    private function getDomainFlags($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return [];
        }

        return $this->getDomainFlagsForMode($domain, $row, $this->getApiPreference($row));
    }

    private function getDomainInfoForMode($domain, $row, $mode)
    {
        if (!$row) {
            return [];
        }

        if ($mode === 'user') {
            $api = $this->getUserApi($row);
            if (!$api) {
                return [];
            }

            $response = $api->action('ud_domain_get', ['domains' => [$domain]]);
            if (!$this->processApiResponse($response)) {
                return [];
            }

            return (array) (($response['body']['domains'][0] ?? []) ?: []);
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return [];
        }

        $response = $api->get('/domains/' . rawurlencode($domain));
        if (!$this->processApiResponse($response)) {
            return [];
        }

        return (array) ($response['body'] ?? []);
    }

    private function getDomainContactsForMode($domain, $row, $mode)
    {
        if (!$row) {
            return [];
        }

        if ($mode === 'user') {
            $info = $this->getDomainInfoForMode($domain, $row, $mode);
            $contacts = [];
            $contact_sets = (array) ($info['contacts'] ?? []);
            foreach ($contact_sets as $type => $contact) {
                if (is_array($contact)) {
                    $contacts[$type] = $this->normalizeContact($contact);
                }
            }

            if (!empty($contacts)) {
                return $contacts;
            }

            $this->Input->setErrors(['contacts' => ['unsupported' => Language::_('Unstoppabledomains.!error.contacts.user_read_only', true)]]);
            return [];
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return [];
        }

        $response = $api->get('/domains/' . rawurlencode($domain) . '/dns/contacts');
        if (!$this->processApiResponse($response)) {
            return [];
        }

        $contacts = [];
        foreach (['owner' => 'registrant', 'admin' => 'admin', 'tech' => 'technical', 'billing' => 'billing'] as $source => $target) {
            $contact = $response['body'][$source] ?? null;
            if (is_array($contact)) {
                $contacts[$target] = $this->normalizeContact($contact);
            }
        }

        return $contacts;
    }

    private function getDomainNameServersForMode($domain, $row, $mode)
    {
        if (!$row) {
            return [];
        }

        if ($mode === 'user') {
            $api = $this->getUserApi($row);
            if (!$api) {
                return [];
            }

            $response = $api->action('ud_dns_nameservers_list', ['domain' => $domain, 'includeDnssec' => false]);
            if (!$this->processApiResponse($response)) {
                return [];
            }

            return $this->normalizeNameservers($response['body']['nameservers'] ?? []);
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return [];
        }

        $response = $api->get('/domains/' . rawurlencode($domain) . '/dns/nameservers');
        if (!$this->processApiResponse($response)) {
            return [];
        }

        return $this->normalizeNameservers($response['body']['items'] ?? $response['body']['nameservers'] ?? []);
    }

    private function setDomainNameserversForMode($domain, $row, $mode, array $vars = [])
    {
        if (!$row) {
            return false;
        }

        $items = [];
        foreach (['ns1', 'ns2', 'ns3', 'ns4', 'ns5'] as $field) {
            if (!empty($vars[$field])) {
                $items[] = trim((string) $vars[$field]);
            }
        }

        if ($mode === 'user') {
            $api = $this->getUserApi($row);
            if (!$api) {
                return false;
            }

            if (!empty($vars['reset_default_nameservers']) || empty($items)) {
                return $this->processApiResponse(
                    $api->action('ud_dns_nameservers_set_default', ['domains' => [['name' => $domain]]])
                );
            }

            return $this->processApiResponse(
                $api->action('ud_dns_nameservers_set_custom', ['domains' => [['name' => $domain, 'nameservers' => array_values($items)]]])
            );
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return false;
        }

        if (empty($items)) {
            $response = $api->delete('/domains/' . rawurlencode($domain) . '/dns/nameservers', [], ['$preview' => 'false']);
        } else {
            $response = $api->put('/domains/' . rawurlencode($domain) . '/dns/nameservers', ['nameservers' => array_values($items)], ['$preview' => 'false']);
        }

        return $this->processApiResponse($response);
    }

    private function getDomainFlagsForMode($domain, $row, $mode)
    {
        if (!$row) {
            return [];
        }

        if ($mode === 'user') {
            $api = $this->getUserApi($row);
            if (!$api) {
                return [];
            }

            $response = $api->action('ud_domain_get', ['domains' => [$domain]]);
            if (!$this->processApiResponse($response)) {
                return [];
            }

            $domain_info = (array) (($response['body']['domains'][0] ?? []) ?: []);
            $flags = (array) ($domain_info['flags'] ?? []);
            $auto_renew_enabled = !empty($domain_info['lifecycle']['autoRenewal']['enabled'])
                || strtolower((string) ($domain_info['lifecycle']['autoRenewal']['status'] ?? '')) === 'enabled';
            $flags['DNS_RENEW'] = ['status' => $auto_renew_enabled ? 'ENABLED' : 'DISABLED'];
            return $flags;
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return [];
        }

        $response = $api->get('/domains/' . rawurlencode($domain) . '/flags');
        if (!$this->processApiResponse($response)) {
            return [];
        }

        return (array) ($response['body'] ?? []);
    }

    private function setTransferLockState($domain, $module_row_id, $locked)
    {
        $row = $this->getModuleRow($module_row_id);
        if (!$row) {
            return false;
        }

        if ($this->getApiPreference($row) === 'user') {
            $api = $this->getUserApi($row);
            if (!$api) {
                return false;
            }

            return $this->processApiResponse($api->action('ud_domain_flags_update', [
                'domains' => [$domain],
                'flags' => [
                    'DNS_TRANSFER_OUT' => [
                        'status' => $locked ? 'DISABLED' : 'ENABLED'
                    ]
                ]
            ]));
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return false;
        }

        return $this->processApiResponse(
            $api->patch('/domains/' . rawurlencode($domain) . '/flags', ['DNS_TRANSFER_OUT' => !$locked], ['$preview' => 'false'])
        );
    }

    private function updateDomainFlags($domain, $row, $mode, array $vars, $service = null)
    {
        if ($mode === 'user') {
            $api = $this->getUserApi($row);
            if (!$api) {
                return false;
            }

            if (array_key_exists('privacy', $vars) || array_key_exists('transfer_lock', $vars)) {
                $flags = [];
                if (array_key_exists('privacy', $vars)) {
                    $flags['DNS_WHOIS_PROXY'] = ['status' => !empty($vars['privacy']) ? 'ENABLED' : 'DISABLED'];
                }
                if (array_key_exists('transfer_lock', $vars)) {
                    $flags['DNS_TRANSFER_OUT'] = ['status' => !empty($vars['transfer_lock']) ? 'DISABLED' : 'ENABLED'];
                }
                if (!empty($flags)) {
                    $this->processApiResponse($api->action('ud_domain_flags_update', [
                        'domains' => [$domain],
                        'flags' => $flags
                    ]));
                }
            }

            if (array_key_exists('auto_renew', $vars)) {
                $payload = [
                    'action' => !empty($vars['auto_renew']) ? 'enable' : 'disable',
                    'domains' => [['name' => $domain]]
                ];
                if (!empty($vars['payment_method_id'])) {
                    $payload['paymentMethodId'] = trim((string) $vars['payment_method_id']);
                } elseif ($service) {
                    $payment_method_id = $this->getServiceMetaValue($service, 'user_payment_method_id');
                    if (!empty($payment_method_id)) {
                        $payload['paymentMethodId'] = $payment_method_id;
                    }
                }
                return $this->processApiResponse($api->action('ud_domain_auto_renewal_update', $payload));
            }

            return true;
        }

        $api = $this->getResellerApi($row);
        if (!$api) {
            return false;
        }

        $flags = [];
        if (array_key_exists('privacy', $vars)) {
            $flags['DNS_WHOIS_PROXY'] = !empty($vars['privacy']);
        }
        if (array_key_exists('auto_renew', $vars)) {
            $flags['DNS_RENEW'] = !empty($vars['auto_renew']);
        }
        if (array_key_exists('transfer_lock', $vars)) {
            $flags['DNS_TRANSFER_OUT'] = empty($vars['transfer_lock']);
        }

        if (empty($flags)) {
            return true;
        }

        return $this->processApiResponse(
            $api->patch('/domains/' . rawurlencode($domain) . '/flags', $flags, ['$preview' => 'false'])
        );
    }

    private function hasAny(array $vars, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $vars)) {
                return true;
            }
        }
        return false;
    }

    private function hasContactChanges(array $vars)
    {
        return $this->hasAny($vars, [
            'registrant_first_name', 'registrant_last_name', 'registrant_organization', 'email', 'phone',
            'address1', 'city', 'state', 'zip', 'country'
        ]);
    }

    private function extractContactVars(array $vars)
    {
        return [
            'registrant_first_name' => $vars['registrant_first_name'] ?? '',
            'registrant_last_name' => $vars['registrant_last_name'] ?? '',
            'registrant_organization' => $vars['registrant_organization'] ?? '',
            'email' => $vars['email'] ?? '',
            'phone' => $vars['phone'] ?? '',
            'address1' => $vars['address1'] ?? '',
            'city' => $vars['city'] ?? '',
            'state' => $vars['state'] ?? '',
            'zip' => $vars['zip'] ?? '',
            'country' => $vars['country'] ?? ''
        ];
    }

    private function normalizeContact(array $contact)
    {
        $phone = '';
        if (!empty($contact['phone']) && is_array($contact['phone'])) {
            $phone = trim((string) (($contact['phone']['dialingPrefix'] ?? '') . '.' . ($contact['phone']['number'] ?? '')), '.');
        } elseif (!empty($contact['phone'])) {
            $phone = (string) $contact['phone'];
        }

        return [
            'external_id' => $contact['id'] ?? '',
            'email' => $contact['email'] ?? '',
            'phone' => $phone,
            'first_name' => $contact['firstName'] ?? '',
            'last_name' => $contact['lastName'] ?? '',
            'company' => $contact['organization'] ?? '',
            'address1' => $contact['street'] ?? '',
            'address2' => $contact['street2'] ?? '',
            'city' => $contact['city'] ?? '',
            'state' => $contact['stateProvince'] ?? '',
            'zip' => $contact['postalCode'] ?? '',
            'country' => $contact['countryCode'] ?? ''
        ];
    }

    private function isSearchResultAvailable(array $result)
    {
        foreach (['availability', 'status', 'purchaseAvailability'] as $key) {
            $value = strtoupper((string) ($result[$key] ?? ''));
            if (in_array($value, ['AVAILABLE', 'FREE', 'PURCHASABLE'])) {
                return true;
            }
            if (in_array($value, ['UNAVAILABLE', 'REGISTERED', 'TAKEN'])) {
                return false;
            }
        }

        return !empty($result['available']);
    }

    private function isResellerDomainAvailable(array $item)
    {
        $availability = strtoupper((string) ($item['availability'] ?? $item['status'] ?? ''));
        if (in_array($availability, ['AVAILABLE', 'FREE'])) {
            return true;
        }
        if (in_array($availability, ['UNAVAILABLE', 'REGISTERED', 'TAKEN'])) {
            return false;
        }
        return !empty($item['available']);
    }

    private function extractAmount(array $item, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($item[$key])) {
                $value = $item[$key];
                if (is_array($value)) {
                    if (isset($value['amount'])) {
                        return (float) $value['amount'];
                    }
                    if (isset($value['value'])) {
                        return (float) $value['value'];
                    }
                }
                if (is_scalar($value)) {
                    return (float) $value;
                }
            }
        }
        return 0.0;
    }

    private function extractFlagEnabled(array $flags, $key)
    {
        if (!isset($flags[$key])) {
            return false;
        }

        if (is_array($flags[$key])) {
            if (array_key_exists('status', $flags[$key])) {
                return strtolower((string) $flags[$key]['status']) === 'enabled';
            }
            if (array_key_exists('enabled', $flags[$key])) {
                return !empty($flags[$key]['enabled']);
            }
        }

        return !empty($flags[$key]);
    }
}
