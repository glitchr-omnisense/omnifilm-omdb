# Changelog

## Unreleased (1.x)

### Fixed
- OMDb's `"Error": "Too many results."` (a search on a very common word) is an empty search,
  `[]`, where it was a `ProviderException`.
