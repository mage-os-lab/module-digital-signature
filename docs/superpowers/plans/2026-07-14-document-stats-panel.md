# Document Stats Panel Implementation Plan

**Goal:** Implement a stats panel (displaying 5 tiles: Total generated, Signed, Expired/Declined, Pending, and Average signing time) above the document grid in the Magento admin panel.

## References
- Design Spec: [2026-07-14-document-stats-panel-design.md](file:///home/nino/PhpstormProjects/mage-os-module-firma-digitale/docs/superpowers/specs/2026-07-14-document-stats-panel-design.md)

---

### Task 1: Period Source & Stats DTOs

**Files:**
- Create: `src/Model/Document/Stats/Summary.php`
- Create: `src/Model/Document/Stats/PeriodSource.php`
- Create: `src/Test/Unit/Model/Document/Stats/PeriodSourceTest.php`

**Steps:**
- [x] **Step 1: Create Summary DTO `Summary.php`**
  Properties: `totalGenerated`, `signedCount`, `signedPercentage`, `expiredDeclinedCount`, `expiredDeclinedPercentage`, `pendingCount`, `avgSigningTimeDays`.
- [x] **Step 2: Create Period Source `PeriodSource.php`**
  Map options (`30d`, `90d`, `365d`, `all`) to date ranges (`from` as DateTime, `to` as DateTime). Default is `30d`.
- [x] **Step 3: Create Unit Test `PeriodSourceTest.php`**
  Verify the mapping of keys to date intervals.
- [x] **Step 4: Run unit tests**
  `vendor/bin/phpunit --filter PeriodSourceTest`

---

### Task 2: Aggregator

**Files:**
- Create: `src/Model/Document/Stats/Aggregator.php`

**Steps:**
- [x] **Step 1: Implement `Aggregator.php`**
  Inject `MageOS\DigitalSignature\Model\ResourceModel\Document\CollectionFactory` and connection to query the `mageos_digitalsignature_document_log` table.
  Calculate:
  1. Total count where `created_at` >= `$from` and <= `$to`.
  2. Signed count (`status = 'signed'`).
  3. Expired/Declined count (`status IN ('expired', 'declined')`).
  4. Pending count (`status = 'sent' AND is_active = 1`).
  5. Average signing time:
     Join logs to calculate average days between `sent` status transition and `signed` status transition.
  Return `Summary` object.

---

### Task 3: Block, Template, Layout & Translations

**Files:**
- Create: `src/Block/Adminhtml/Document/StatsPanel.php`
- Create: `src/view/adminhtml/templates/document/stats_panel.phtml`
- Modify: `src/view/adminhtml/layout/mageos_digitalsignature_document_index.xml` (renamed: `digitalsignature_document_index.xml`)
- Modify: `src/i18n/it_IT.csv` (and other CSV files)

**Steps:**
- [x] **Step 1: Create Block `StatsPanel.php`**
  Extend `Magento\Backend\Block\Template`. Read request param `stats_period`. Get `Summary` from `Aggregator`.
- [x] **Step 2: Create Template `stats_panel.phtml`**
  Display select box of periods and the 5 styled metric cards. Add a simple GET form that submits to the same page.
- [x] **Step 3: Update Layout XML**
  Inject the block above the UI component grid in `digitalsignature_document_index.xml`.
- [x] **Step 4: Translate new strings**
  Append translation strings to all CSV files.
- [x] **Step 5: Run all unit tests**
  Confirm the full test suite remains green.
