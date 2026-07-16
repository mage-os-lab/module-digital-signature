# PDF Builder Frontend Implementation Plan

**Goal:** Implement a visual PDF signature tag builder in the admin panel using pdf.js, allowing merchants to drag and drop signature boxes onto a preview of their uploaded PDF templates.

## References
- Design Spec: [2026-07-14-pdf-builder-frontend-design.md](file:///home/nino/PhpstormProjects/mage-os-module-firma-digitale/docs/superpowers/specs/2026-07-14-pdf-builder-frontend-design.md)

---

### Task 1: Configuration, CSP & Local pdf.js Assets

**Files:**
- Modify: `src/etc/adminhtml/system.xml`
- Create: `src/Model/Csp/PdfJsPolicyCollector.php`
- Modify: `src/etc/adminhtml/di.xml` (or create if not present)
- Create: `src/view/adminhtml/web/js/lib/pdfjs/pdf.min.js`
- Create: `src/view/adminhtml/web/js/lib/pdfjs/pdf.worker.min.js`

**Steps:**
- [x] **Step 1: Add Configuration Fields**
  Add `pdfjs_source` (Local/CDN) and `pdfjs_cdn_url` fields under the general section of `system.xml`.
- [x] **Step 2: Create CSP Policy Collector**
  Add `PdfJsPolicyCollector.php` to conditionally load `script-src` and `worker-src` policies if CDN is selected.
- [x] **Step 3: Download pdf.js assets**
  Download version `2.16.105` of `pdf.min.js` and `pdf.worker.min.js` into the local JS directory.

---

### Task 2: Upload Controller Modifications & Tmp Controllers

**Files:**
- Modify: `src/Controller/Adminhtml/Template/Upload.php`
- Create: `src/Controller/Adminhtml/Template/PreviewTmp.php`
- Create: `src/Controller/Adminhtml/Template/InjectTag.php`

**Steps:**
- [x] **Step 1: Modify Upload Controller**
  Change `Upload::validateUploadedPdf()` so that when validation fails with "no signature tag found" warning, the temporary file is NOT deleted, and the JSON response contains `warning: 'no_tag'` + file details.
- [x] **Step 2: Create PreviewTmp Controller**
  Create controller that serves the raw binary content of a temporary uploaded PDF from the tmp folder (ACL protected).
- [x] **Step 3: Create InjectTag Controller**
  Create controller that receives the temp filename, page number, coordinates (x/y in points), and width/height (W/H in mm), calls `SignatureTagInjector::injectAt()`, runs `TemplateValidator::validate()`, and returns standard success response.

---

### Task 4: JS Coordinate Conversion & Component Frontend

**Files:**
- Create: `src/view/adminhtml/web/js/pdf-builder-coords.js`
- Create: `tests/js/pdf-builder-coords.test.js`
- Create: `package.json`
- Create: `src/view/adminhtml/web/js/pdf-builder-modal.js`
- Create: `src/view/adminhtml/web/js/pdf-uploader-mixin.js`
- Modify: `src/view/adminhtml/requirejs-config.js` (created)

**Steps:**
- [x] **Step 1: Create `pdf-builder-coords.js` & JS Test**
  Write coordinate calculations: scale pixels to PDF points, invert Y axis (canvas origin is top-left, PDF origin is bottom-left), convert points to mm. Verify calculations using Node `node:test` in `tests/js/pdf-builder-coords.test.js`.
- [x] **Step 2: Create `pdf-builder-modal.js`**
  Implement knockout component that renders pdf.js on a `<canvas>` element, handles mouse events to draw a bounding box, and submits POST request to `InjectTag` controller.
- [x] **Step 3: Create Mixin & RequireJS Config**
  Extend Magento's standard `uploader` JS component via mixin to intercept `warning: 'no_tag'`, open the modal builder, and update UI state on successful tag injection.
- [x] **Step 4: Update Translations**
  Translate all modal builder labels, button states, and errors to all 8 target languages.
