# Changelog

## [Unreleased]

### Fixed

- A 2xx answer whose body is not JSON (a proxy's or a maintenance page) is a `ProviderException`
  (`An answer that is not JSON (HTTP 200).`), where it was read as an empty answer.
  `order()` returned an `Order` with an empty reference, the status unknown and a PHP warning.
- A `token_expires_at` PHP cannot read as a date is an `InvalidConfigException` (an `\Exception`
  still), where PHP's own exception escaped the factory.
