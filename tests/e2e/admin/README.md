# E2E Tests: Admin - Canvas-Vertical Many-to-Many

## Overview

Tests for Phase 3.5 Part 2 - Many-to-Many Canvas-Vertical Assignment functionality.

## Test Coverage

### Canvas-Vertical Assignment (`test-canvas-vertical-assignment.spec.js`)

**T1:** Create vertical and verify in admin list
- Creates test vertical via admin UI
- Verifies it appears in vertical list
- Cleanup: deletes test vertical

**T2:** Assign canvas to multiple verticals
- Edits existing canvas
- Assigns to 3 verticals (or all if < 3)
- Verifies checkboxes persist after save

**T3:** Verify canvas appears in assigned vertical areas
- Gets canvas with assignments
- Navigates to vertical index page
- Verifies canvas is visible in list

**T4:** Remove vertical assignment
- Unchecks vertical from canvas
- Saves and verifies removal
- Confirms checkbox state persists

**T5:** Edge case - Canvas with no verticals
- Unchecks all verticals
- Verifies warning message appears
- Tests validation UX

**T6:** Canvas assigned to all verticals
- Checks all available verticals
- Saves and verifies all remain checked
- Tests maximum assignment scenario

**T7:** Create canvas from scratch with vertical assignment
- Creates new canvas via admin modal
- Assigns to vertical during creation
- Verifies redirect to edit page

## Setup

### Prerequisites

```bash
npm install
npx playwright install chromium
```

### Environment

- **Target:** http://158.69.25.114 (VM100 - v2)
- **Credentials:** admin@sunyataconsulting.com / password
- **Browser:** Chromium (headless)

## Running Tests

### All Tests

```bash
npx playwright test tests/e2e/admin/test-canvas-vertical-assignment.spec.js
```

### Specific Test

```bash
npx playwright test tests/e2e/admin/test-canvas-vertical-assignment.spec.js -g "T2"
```

### With UI (headed mode)

```bash
npx playwright test tests/e2e/admin/test-canvas-vertical-assignment.spec.js --headed
```

### Debug Mode

```bash
npx playwright test tests/e2e/admin/test-canvas-vertical-assignment.spec.js --debug
```

## Expected Results

### Successful Run

```
Running 7 tests using 1 worker

  ✓ T1: Create vertical and verify in admin list (5.2s)
  ✓ T2: Assign canvas to multiple verticals (3.8s)
  ✓ T3: Verify canvas appears in assigned vertical areas (2.1s)
  ✓ T4: Remove vertical assignment and verify (3.2s)
  ✓ T5: Edge case - Canvas with no verticals shows warning (2.9s)
  ✓ T6: Canvas assigned to all verticals (4.1s)
  ✓ T7: Create canvas from scratch and assign to verticals (6.5s)

  7 passed (27.8s)
```

## Test Data

Tests use existing canvas and verticals on VM100. Test creates temporary data (prefixed with `test-`) and cleans up after itself.

**Created during tests:**
- Temporary verticals: `test-vertical-{timestamp}`
- Temporary canvas: `test-canvas-{timestamp}`

**Cleanup:**
- Automatic cleanup via `deleteVertical()` helper
- Failed tests may leave test data (review admin UI if needed)

## Troubleshooting

### Login Failures

- Verify credentials are correct
- Check if admin user exists on VM100
- Ensure user has `access_level = 'admin'`

### Canvas Not Found Errors

- Ensure VM100 has at least one canvas template
- Run migrations 011 and 012 before testing
- Check `canvas_vertical_assignments` table exists

### Checkbox Selectors Not Found

- Verify canvas-edit.php has many-to-many UI (Phase 3.5 Part 2)
- Check if page loaded correctly (wait for networkidle)
- Inspect page with `--headed` mode to debug

### Warning/Success Messages Not Appearing

- Increase timeout values in test
- Check if alert classes changed (`alert-success`, `alert-warning`)
- Verify message appears in UI manually

## Related Files

**Backend:**
- `migrations/011_canvas_vertical_assignments.sql` - Junction table
- `migrations/012_migrate_canvas_verticals.sql` - Data migration
- `app/src/Services/CanvasService.php` - Business logic

**Frontend:**
- `app/public/admin/canvas-edit.php` - Checkbox UI
- `app/public/areas/*/index.php` - Canvas lists per vertical

## Notes

- Tests run against **VM100 (v2 environment)**, not Hostinger
- Tests are **idempotent** - can run multiple times safely
- Some tests may skip if data conditions not met (e.g., no verticals available)
- Tests validate UI behavior, not just API responses

---

**Created:** 2026-02-19
**Phase:** 3.5 Part 2 - Many-to-Many Canvas-Vertical
**Status:** Ready for testing
