# Project Instructions

## Examples

Normal applications return one `MailAutomation::fromConfig(new MailAutomationConfig(...))` instance from their bootstrap and let the package CLI own connection, locking, synchronization and action discovery. Content-driven actions still use public methods with `#[OnMailAction(..., folder: ...)]`; `scanAutomations()` / `addMailAutomation()` / `addMailActions()` are lower-level integration APIs. Put cooperating objects in one call so they share one choice set; do not add a forwarding `OnFolderAutomation` wrapper just to reach the matcher. See `docs/direct-mail-actions.md` and `examples/07-ai-classification.php`.

Examples explicitly demonstrating legacy folder automation use `#[OnFolderAutomation(...)]` with `MailAutomation::addRules()`. The programmatic `onFolder(...)->addAutomation(...)` API is only for examples explicitly demonstrating the programmatic registration API or framework-level integration code.

Generated messages are `AiMailDraft` instances extending `AiMail`; only drafts expose `send()`, `draft()`, setters and attachments. `run()` scans Sent before Inbox. Keep semantic routing separate from processing flags and delivery safety. Dry-run may select actions but must never invoke AI business handlers or execute their schedules. Tests must not call live AI providers or mailboxes.
