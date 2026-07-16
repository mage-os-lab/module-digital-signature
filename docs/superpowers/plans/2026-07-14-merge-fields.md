# Merge Fields Implementation Plan

**Goal:** Implement dynamic merge fields in the PDF template so that merchant can place placeholders like `{FIELD:order_number}` or `{FIELD:grand_total}` and have them dynamically resolved from the current order/invoice.

## References
- Design Spec: [2026-07-14-merge-fields-design.md](file:///home/nino/PhpstormProjects/mage-os-module-firma-digitale/docs/superpowers/specs/2026-07-14-merge-fields-design.md)

---

### Task 1: Interfaces, Models & DI

**Files:**
- Create: `src/Api/MergeFieldProviderInterface.php`
- Create: `src/Api/MergeFieldPoolInterface.php`
- Create: `src/Model/MergeField/Context.php`
- Create: `src/Model/MergeField/Pool.php`
- Modify: `src/etc/di.xml`

**Steps:**
- [x] **Step 1: Create contracts & DTOs**
  Add provider interface, pool interface, and Context DTO.
- [x] **Step 2: Create Pool implementation**
  Add registry class that collects providers injected via DI.
- [x] **Step 3: Register preferences in di.xml**
  Declare dependencies mapping interface to concrete models.

---

### Task 2: Core Merge Field Providers & Unit Tests

**Files:**
- Create: `src/Model/MergeField/OrderNumber.php`
- Create: `src/Model/MergeField/GrandTotal.php`
- Create: `src/Model/MergeField/CustomerName.php`
- Create: `src/Model/MergeField/OrderDate.php`
- Create: `src/Model/MergeField/InvoiceDate.php`
- Modify: `tests/stubs/MagentoStubs.php` (added datetime, pricing and invoice interfaces)
- Create: `src/Test/Unit/Model/MergeField/MergeFieldProvidersTest.php`
- Create: `src/Test/Unit/Model/MergeField/PoolTest.php`

**Steps:**
- [x] **Step 1: Implement the 5 core providers**
  Order number, Grand total (formatted via Magento PriceCurrency), Customer name, Order date (formatted via timezone locale), Invoice date (available only for invoice triggers).
- [x] **Step 2: Write Unit Tests**
  Verify the format and trigger compatibility conditions for each provider.
- [x] **Step 3: Run unit tests**
  Ensure all new tests pass.

---

### Task 3: PDF Sostitution & Controller Validation

**Files:**
- Modify: `src/Model/Pdf/TagReplacer.php`
- Create: `src/Model/Pdf/PreviewOrderContext.php`
- Modify: `src/Controller/Adminhtml/Template/Save.php`
- Modify: `src/Model/Service/DocumentProcessor.php`
- Modify: `src/Test/Unit/Model/Pdf/SignatureTagInjectorTest.php`
- Modify: `src/Test/Unit/Model/Pdf/TagReplacerTest.php`
- Modify: `src/Test/Unit/Model/Pdf/TemplateValidatorTest.php`

**Steps:**
- [x] **Step 1: Scan and replace field tags in TagReplacer**
  Find `{FIELD:code}` regex match and replace it by querying the pool. Sbiancamento byte and xref updates done in a single incremental update.
- [x] **Step 2: Save validation**
  Controller checks if all field tags in the template PDF are supported and compatible with the selected/default trigger. Reject save with explicit error message if not.
- [x] **Step 3: Real execution path in DocumentProcessor**
  Load order & invoice to construct Context and pass it to TagReplacer on document generation.
- [x] **Step 4: Update test setups**
  Inject mock pool & preview context in unit test setups. Add new replacement tests to TagReplacerTest. All 158 tests passing successfully.
