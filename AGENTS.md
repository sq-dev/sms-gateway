## Learned User Preferences

## Learned Workspace Facts
- `sqdev/sms-gateway` is a PHP Composer library for provider-agnostic SMS sending with a unified send API and normalized delivery-status model.
- The library was built contract-first so provider adapters can be added incrementally without forcing consumer call sites to change.
- The package targets PHP `^8.1`, uses PHPUnit 10 for tests, and relies on PSR HTTP interfaces plus `php-http/discovery` for HTTP-client auto-discovery.
- The current package surface includes a lightweight `Sender` facade and built-in adapters for `Payom.tj`, `OsonSMS`, and `SMSGate`.
