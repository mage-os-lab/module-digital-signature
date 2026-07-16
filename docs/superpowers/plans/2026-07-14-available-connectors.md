# Available Connectors Catalog Implementation Plan

**Goal:** Implement a static, informative catalog of available and planned signature providers (specifically highlighting Adobe Acrobat Sign "in development") in the Magento Admin panel and repository documentation.

## References
- Design Spec: [2026-07-14-available-connectors-catalog-design.md](file:///home/nino/PhpstormProjects/mage-os-module-firma-digitale/docs/superpowers/specs/2026-07-14-available-connectors-catalog-design.md)

---

### Task 1: Model DTOs & Catalog

**Files:**
- Create: `src/Model/Connector/AvailableConnector.php`
- Create: `src/Model/Connector/AvailableConnectorsCatalog.php`
- Create: `src/Test/Unit/Model/Connector/AvailableConnectorsCatalogTest.php`

**Steps:**
- [x] **Step 1: Create DTO `AvailableConnector.php`**
  Add class representing a connector with fields: `code` (string), `name` (string), `status` (string), `description` (string), `link` (string).
- [x] **Step 2: Create Catalog `AvailableConnectorsCatalog.php`**
  Add class returning the list of available/planned connectors. Hardcode the Adobe Acrobat Sign connector with status "in_development".
- [x] **Step 3: Create Unit Test `AvailableConnectorsCatalogTest.php`**
  Verify the catalog returns the expected entry for Adobe Acrobat Sign and that it has all fields properly populated.
- [x] **Step 4: Run unit tests**
  `vendor/bin/phpunit --filter AvailableConnectorsCatalogTest`

---

### Task 2: Block & Template

**Files:**
- Create: `src/Block/Adminhtml/System/Config/AvailableConnectors.php`
- Create: `src/view/adminhtml/templates/system/config/available_connectors.phtml`

**Steps:**
- [x] **Step 1: Create Block `AvailableConnectors.php`**
  Create block extending `Magento\Config\Block\System\Config\Form\Field`. Inject the `AvailableConnectorsCatalog` model and expose methods `getConnectors()` and `getDeveloperGuideUrl()`. Set the template `MageOS_DigitalSignature::system/config/available_connectors.phtml`.
- [x] **Step 2: Create Template `available_connectors.phtml`**
  Iterate through the catalog. Display name, status badge, description, and link. Render a static row at the bottom linking to the developer guide for custom connector integrations.

---

### Task 3: Configuration & Documentation

**Files:**
- Modify: `src/etc/adminhtml/system.xml`
- Create: `docs/available-connectors.md`
- Modify: `docs/sign-provider-integration.md`
- Modify: `src/i18n/it_IT.csv` (and other CSV files via translation script)

**Steps:**
- [x] **Step 1: Add field to `src/etc/adminhtml/system.xml`**
  Add field `available_connectors` inside the `providers` group, using `AvailableConnectors` as `frontend_model`.
- [x] **Step 2: Create `docs/available-connectors.md`**
  Create markdown document detailing planned/available connectors.
- [x] **Step 3: Link in `docs/sign-provider-integration.md`**
  Cross-link from provider integration docs to the available connectors page.
- [x] **Step 4: Update Translations**
  Translate new strings to all 8 target languages.
- [x] **Step 5: Run all unit tests**
  Confirm the full test suite passes.
