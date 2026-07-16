# Signature Reminders Implementation Plan

**Goal:** Implement automatic notifications (reminders) for documents that remain in `SENT` status for a configurable period, including repeated notifications for customers and a single escalation notification for administrators.

## References
- Design Spec: [2026-07-14-signature-reminders-design.md](file:///home/nino/PhpstormProjects/mage-os-module-firma-digitale/docs/superpowers/specs/2026-07-14-signature-reminders-design.md)
- Testing strategy: [testing.md](file:///home/nino/PhpstormProjects/mage-os-module-firma-digitale/docs/testing.md)

---

### Task 1: Database Schema & Entity Updates

**Files:**
- Modify: `src/etc/db_schema.xml`
- Modify: `src/etc/db_schema_whitelist.json`
- Modify: `src/Api/Data/DocumentInterface.php`
- Modify: `src/Model/Document.php`
- Modify: `tests/Support/FakeDocument.php`

**Steps:**
- [x] **Step 1: Add fields to `src/etc/db_schema.xml`**
  Add `reminder_count`, `last_reminder_at`, and `escalation_sent_at` columns to `mageos_digitalsignature_document` table.
- [x] **Step 2: Add fields to `src/etc/db_schema_whitelist.json`**
  Add columns to `mageos_digitalsignature_document` object.
- [x] **Step 3: Define constants and methods in `src/Api/Data/DocumentInterface.php`**
  Add constants and get/set methods for `reminder_count`, `last_reminder_at`, and `escalation_sent_at`.
- [x] **Step 4: Implement methods in `src/Model/Document.php`**
  Implement getter/setter methods.
- [x] **Step 5: Implement methods in `tests/Support/FakeDocument.php`**
  Implement getter/setter methods for unit tests mock.
- [x] **Step 6: Run existing tests**
  Verify nothing is broken: `composer test`.

---

### Task 2: Configuration & Email Templates

**Files:**
- Modify: `src/etc/adminhtml/system.xml`
- Modify: `src/etc/config.xml`
- Modify: `src/etc/email_templates.xml`
- Modify: `src/i18n/it_IT.csv` (and other translation files if needed)

**Steps:**
- [x] **Step 1: Add settings to `src/etc/adminhtml/system.xml`**
  Add the `reminders` config group: `customer_enabled`, `customer_threshold_days`, `customer_interval_days`, `customer_max_reminders`, `admin_enabled`, `admin_threshold_days`, `batch_limit` inside the module tab.
- [x] **Step 2: Add default config in `src/etc/config.xml`**
  Define defaults for `digital_signature/reminders/*`:
  - `customer_enabled` = 0
  - `customer_threshold_days` = 3
  - `customer_interval_days` = 3
  - `customer_max_reminders` = 2
  - `admin_enabled` = 1
  - `admin_threshold_days` = 7
  - `batch_limit` = 50
- [x] **Step 3: Define email templates in `src/etc/email_templates.xml`**
  Add template nodes for customer reminder and admin escalation under the frontend area.
- [x] **Step 4: Add translation strings**
  Add translation keys/values to `src/i18n/it_IT.csv` and `src/i18n/en_US.csv`.

---

### Task 3: Eligibility Calculator (Pure Logic)

**Files:**
- Create: `src/Model/Reminder/EligibilityCalculator.php`
- Create: `src/Test/Unit/Model/Reminder/EligibilityCalculatorTest.php`

**Steps:**
- [x] **Step 1: Create `EligibilityCalculator` class**
  Implement the logic to check if a document is eligible for customer reminder and/or admin escalation.
- [x] **Step 2: Create unit tests in `EligibilityCalculatorTest`**
  Write test cases for:
  - Not eligible (time not elapsed).
  - Customer reminder due (first time).
  - Customer reminder not due yet (interval not elapsed).
  - Customer reminder due (subsequent times).
  - Customer reminder max count reached.
  - Admin escalation due.
  - Admin escalation already sent.
  - All configurations disabled.
- [x] **Step 3: Run the unit tests**
  `vendor/bin/phpunit --filter EligibilityCalculatorTest`

---

### Task 4: Notifier Update

**Files:**
- Modify: `src/Model/Notification/Notifier.php`

**Steps:**
- [x] **Step 1: Add reminder paths constants**
  Add `XML_PATH_CUSTOMER_REMINDER_ENABLED`, `XML_PATH_CUSTOMER_REMINDER_TEMPLATE`, `XML_PATH_ADMIN_ESCALATION_ENABLED`, `XML_PATH_ADMIN_ESCALATION_TEMPLATE` to `Notifier`.
- [x] **Step 2: Implement notifier methods**
  - Implement `notifyDocumentReminder(DocumentInterface $document)`
  - Implement `notifyDocumentEscalation(DocumentInterface $document)`
- [x] **Step 3: Run existing unit tests**
  Ensure no regressions: `composer test`.

---

### Task 5: Cron Job Scheduler

**Files:**
- Modify: `src/etc/crontab.xml`
- Create: `src/Cron/SignatureReminder.php`

**Steps:**
- [x] **Step 1: Define cron job in `src/etc/crontab.xml`**
  Define `digitalsignature_reminder` daily cron job.
- [x] **Step 2: Implement cron job class `src/Cron/SignatureReminder.php`**
  Walk through `SENT` documents matching `is_active = 1`, run them through `EligibilityCalculator`, notify via `Notifier` and update document columns (`reminder_count`, `last_reminder_at`, `escalation_sent_at`) using the ResourceModel.
- [x] **Step 3: Run the full test suite**
  Verify all tests pass: `composer test`.
