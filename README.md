# MageUpgrade AutoUpgrader

**Magento 2.4.8 Automated Upgrade Plugin**

Fully automated Magento version migration with modern admin UI, real-time progress tracking, compatibility scanning, auto-fix engine, extension management, and backup/rollback support.

---

## Features

- **Version Selector** — Fetches available Magento versions from Packagist, shows patches & security updates
- **Compatibility Scanner** — Scans custom code for deprecated classes, methods, PHP incompatibilities, plugin conflicts, template overrides, and composer constraints
- **Auto-Fix Engine** — Automatically fixes detected issues (deprecated class replacements, PHP function updates, loosened composer constraints)
- **Extension Manager** — Finds compatible versions for all 3rd-party extensions and upgrades them
- **Real-Time Progress** — Animated step-by-step timeline with live status updates
- **User Confirmation** — Always asks before executing; shows full upgrade plan first
- **Backup & Rollback** — Full backup (database + files) before upgrade, one-click rollback
- **CLI Support** — `autoupgrader:scan`, `autoupgrader:upgrade`, `autoupgrader:rollback`
- **Modern Admin UI** — Clean dashboard with cards, progress bars, modals, and responsive design

---

## Requirements

- Magento 2.4.x (optimized for 2.4.8)
- PHP 8.1+ (8.3/8.4 recommended)
- Composer 2.x

---

## Installation

### Via app/code (manual)

```bash
cp -r MageUpgrade /path/to/magento/app/code/
bin/magento module:enable MageUpgrade_AutoUpgrader
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Via Composer (packagist/private repo)

```bash
composer require mageupgrade/module-autoupgrader
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

---

## Usage

### Admin Panel

Navigate to **Admin > AutoUpgrader** in the sidebar:

| Menu Item | Description |
|-----------|-------------|
| Upgrade Dashboard | Select version, run scan, confirm & execute upgrade |
| Compatibility Scan | Detailed scan with file-level issues and extension table |
| Upgrade History | Grid listing all past upgrades with status |

### CLI Commands

```bash
# Scan for compatibility issues
bin/magento autoupgrader:scan 2.4.8-p4

# Run full upgrade (interactive confirmation)
bin/magento autoupgrader:upgrade 2.4.8-p4

# Skip confirmation
bin/magento autoupgrader:upgrade 2.4.8-p4 --yes

# Rollback a failed upgrade
bin/magento autoupgrader:rollback <upgrade_id>
```

---

## Upgrade Workflow

1. **Select version** — Pick target from dropdown (includes patches)
2. **Scan** — Finds impacted files, deprecated code, extension issues
3. **Review** — Shows severity, auto-fix availability, extension compatibility
4. **Confirm** — Modal asks confirmation with full step preview
5. **Execute** — Backup → Auto-fix → Extensions → Composer → Setup → Compile → Deploy → Verify
6. **Track** — Real-time animated progress with step-by-step timeline

---

## Architecture

```
app/code/MageUpgrade/AutoUpgrader/
├── Api/                    # Service contracts (7 interfaces)
├── Block/Adminhtml/        # Admin blocks
├── Console/Command/        # CLI commands (scan, upgrade, rollback)
├── Controller/Adminhtml/   # Admin controllers
├── Helper/                 # Configuration helper
├── Model/                  # Data models & resource models
├── Service/                # Core services (7 implementations)
├── etc/                    # Module config, DI, routes, ACL, schema
├── i18n/                   # Translations
└── view/adminhtml/         # Templates, layouts, JS, CSS
```

---

## License

Proprietary
