# Unstoppable Domains Module for Blesta

A registrar module that integrates [Unstoppable Domains](https://unstoppabledomains.com) with [Blesta](https://www.blesta.com), enabling automated domain registration, transfers, renewals, and full lifecycle management directly from your Blesta installation.

## Features

- **Dual API Support** -- works with both the Reseller API (v3) and User API, selectable per account row, package, or individual service
- **Domain Registration** -- register domains through either API with full contact details and nameserver configuration
- **Domain Transfers** -- inbound transfers with EPP/auth code support (Reseller API)
- **Domain Renewals** -- manual and auto-renewal with configurable payment methods
- **Nameserver Management** -- set up to 5 custom nameservers or reset to Unstoppable Domains defaults
- **DNS Record Management** -- create and delete A, AAAA, CNAME, MX, TXT, SRV, and NS records with TTL control
- **DNSSEC** -- enable/disable DNSSEC signing (Reseller API)
- **WHOIS Privacy** -- toggle ID protection per domain
- **Transfer Lock** -- lock/unlock domains to prevent unauthorized outbound transfers
- **EPP/Auth Code Retrieval** -- fetch authorization codes for outbound transfers (Reseller API)
- **Contact Management** -- view and update registrant, admin, tech, and billing contacts (Reseller API)
- **Auto-Renewal Control** -- enable/disable auto-renew with optional User API payment method
- **Domain Date Sync** -- cron task that synchronizes expiration dates from the API to keep Blesta renewal dates accurate
- **TLD Pricing Import** -- pull TLD pricing from the Reseller API with automatic currency conversion
- **API Request Logging** -- all API calls are logged through Blesta's built-in module log for easy debugging

## Requirements

- Blesta v5.x or later
- PHP 7.4 or later with cURL extension
- An Unstoppable Domains Reseller account and/or User API key

## Installation

1. Download or clone this repository
2. Copy the `unstoppabledomains` folder into your Blesta installation:
   ```
   /components/modules/unstoppabledomains/
   ```
3. Log in to Blesta admin and navigate to **Settings > Company > Modules**
4. Click **Install** next to "Unstoppable Domains"

## Configuration

### Adding an Account Row

After installation, add at least one account row:

1. Go to **Settings > Company > Modules > Unstoppable Domains**
2. Click **Add Account**
3. Fill in:
   - **Account Label** -- a friendly name (e.g., "Production Reseller")
   - **API Mode** -- choose Reseller API or User API as the default
   - **Reseller Bearer Token** -- your Reseller API v3 token (required if using Reseller mode)
   - **User API Key** -- your User API key (required if using User mode)
   - **Base URLs** -- pre-filled with production endpoints; change only if using a custom environment
   - **Sandbox** -- check to use the sandbox endpoint (Reseller only)

### Creating a Package

1. Go to **Packages > New**
2. Select "Unstoppable Domains" as the module and pick your account row
3. On the module options tab:
   - **Default API Mode** -- inherit from account row, or override to Reseller/User
   - **Supported TLDs** -- check the TLDs you want to offer
   - **Default Nameservers** -- optional; pre-filled into new orders

### API Mode Inheritance

The API mode is resolved in this order:

1. **Service-level override** (set per order/service)
2. **Package-level default** (set in package config)
3. **Account row default** (set in module row config)

Setting any level to "Inherit Default" falls through to the next level.

## How It Works

### Order Flow

When a customer places a domain order:

1. Blesta calls `addService()` with the order details
2. The module resolves which API mode to use
3. For **registrations**: calls the Reseller `/domains` endpoint or User API cart + checkout flow
4. For **transfers**: submits the domain + auth code to the Reseller transfer endpoint
5. Service metadata (operation ID, order ID, API mode) is stored for future reference

### Renewal Flow

1. Blesta triggers `renewService()` at the renewal date
2. **Reseller API**: sends a POST to `/domains/{domain}/renewals`
3. **User API**: adds a renewal to cart and checks out using account balance or a saved payment method

### Cancellation and Suspension

- **Cancel**: disables auto-renew so the domain expires naturally
- **Suspend**: disables auto-renew; **Unsuspend**: re-enables auto-renew

### Cron Task -- Domain Date Sync

A background task runs every 6 hours to:

1. Query the API for each active domain's expiration date
2. Update Blesta's `date_renews` field if the remote date differs

This keeps Blesta's billing cycle aligned with the actual registry expiration.

## Client-Facing Tabs

| Tab | Description |
|-----|-------------|
| **Nameservers** | View and update up to 5 nameservers, or reset to defaults |
| **DNS Records** | Add and delete DNS records (A, AAAA, CNAME, MX, TXT, SRV, NS) with TTL |
| **DNSSEC** | Enable or disable DNSSEC signing (Reseller API only) |
| **Settings** | Toggle WHOIS privacy, auto-renew, and transfer lock |

## Admin-Facing Tabs

| Tab | Description |
|-----|-------------|
| **Actions** | Refresh remote data, retrieve EPP codes, view contacts and domain info |
| **DNSSEC** | Enable/disable DNSSEC with status and detail view |

## API Mode Differences

| Feature | Reseller API | User API |
|---------|:---:|:---:|
| Domain Registration | Yes | Yes |
| Domain Transfer | Yes | No |
| Domain Renewal | Yes | Yes |
| Nameservers | Yes | Yes |
| DNS Records | Yes | Yes |
| DNSSEC | Yes | No |
| WHOIS Privacy | Yes | Yes |
| Transfer Lock | Yes | Yes |
| EPP/Auth Code | Yes | No |
| Contact Management | Yes | Read-only |
| TLD Pricing Import | Yes | No |

## File Structure

```
unstoppabledomains/
├── config.json                          # Module metadata and version
├── unstoppabledomains.php               # Main module class
├── lib/
│   └── UnstoppableDomainsApi.php        # REST API wrapper (cURL, HTTPS-only)
├── language/
│   └── en_us/
│       └── unstoppabledomains.php       # English language strings
└── views/
    └── default/
        ├── add_row.pdt                  # Add account form
        ├── edit_row.pdt                 # Edit account form
        ├── manage.pdt                   # Account list view
        ├── row_form.pdt                 # Reusable row form fields
        ├── tab_admin_actions.pdt        # Admin actions tab
        ├── tab_admin_dnssec.pdt         # Admin DNSSEC tab
        ├── tab_client_dns.pdt           # Client DNS management
        ├── tab_client_dnssec.pdt        # Client DNSSEC tab
        ├── tab_client_nameservers.pdt   # Client nameserver management
        └── tab_client_settings.pdt      # Client domain settings
```

## Security

- API tokens are stored encrypted in the database
- All API communication is HTTPS-only with SSL verification enforced
- Error messages from the API are sanitized before display (HTML tags stripped)
- Sensitive fields (auth codes, tokens) are redacted in admin domain info views
- DNS TTL values are validated and clamped to safe ranges (60--86400 seconds)
- Domain input is validated against a strict regex pattern

## Troubleshooting

- **API errors**: Check **Tools > Logs > Module** in Blesta admin for full request/response logs
- **Cron not syncing dates**: Verify the cron task is enabled under **Settings > Company > Automation**
- **Transfer fails on User API**: Transfers are only supported via the Reseller API
- **DNSSEC unavailable**: DNSSEC management requires Reseller API mode

## License

MIT License. See [LICENSE](LICENSE) for details.

## Author

[BiswasHost](https://www.biswashost.com)
