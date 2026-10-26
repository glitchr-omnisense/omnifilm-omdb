# omnifilm/omdb

[omnisense/omnifilm](https://github.com/glitchr-omnisense/omnifilm)'s OMDb, under any PHP host: films,
series and episodes by their IMDb number, with the IMDb, Rotten Tomatoes and Metacritic scores OMDb
gathers; a search by title (its first page: ten answers); a season's episodes. Its `"N/A"` is read
as nothing; `"Response": "False"` is "not found", or an error when it says the key is wrong or the
limit reached.

```php
$omdb = (new OmdbFactory($httpClient))->create(['api_key' => getenv('OMDB_KEY')]);
$matrix = $omdb->work(WorkRef::imdb('tt0133093'));
```

Options: `api_key` (required), `api` ("https://www.omdbapi.com/").

Written on Symfony's HTTP client contracts, like the family: an API of a few query parameters,
no client known that runs on them (and takes the application's client).

## OMDb's terms, as read on 2026-10-24

- Its content is licensed [CC BY-NC 4.0](https://creativecommons.org/licenses/by-nc/4.0/)
  (omdbapi.com): credit OMDb, no commercial use. Its [terms](https://www.omdbapi.com/legal.htm)
  allow personal, non-commercial use only.
- Free keys: 1,000 requests a day; the poster API is for its patrons (not used here).

## Verified, and not

- Verified (the core's harness, PHP 8.4): a film with its three scores, a series and its
  creators, a season, a search, not found, a limit reached; `BareTest`; PHPStan max. The answers
  are **shaped after OMDb's documented example and written by hand**: no key was used.
- Not verified live: no key.

## Responsibility

An independent, unofficial client, affiliated with, endorsed by or sponsored by none of the services
it reaches. The MIT licence covers this code only and grants no right to the service or its data:
whoever runs it complies with the terms above and obtains what they require. The authors publish
it free of charge, make no commercial use of it and accept no liability for its use.

## Licence

MIT.
