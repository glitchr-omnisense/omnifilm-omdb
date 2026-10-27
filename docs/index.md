# omnifilm/omdb

OMDb for [omnisense/omnifilm](https://github.com/glitchr-omnisense/omnifilm): films, series and
episodes by their IMDb number, with the IMDb, Rotten Tomatoes and Metacritic scores OMDb gathers; a
search by title; a season's episodes. Every call is `GET https://www.omdbapi.com/` with the key and
`r=json` in the query string:

| Operation | Query |
|---|---|
| search | `s=<words>`, `type=movie\|series`, `y=<year>`, `page=1` |
| a work | `i=<IMDb number>`, `plot=full` |
| a season | `i=<IMDb number>`, `Season=<n>` |

OMDb has no sandbox. No PHP client running on Symfony's HTTP client contracts was found to reuse.

## Installation

```sh
composer require omnifilm/omdb
```

Requires PHP 8.2, `omnisense/omnifilm` (1.x) and `symfony/http-client` (6.4, 7 or 8).

## Credentials

An **API key** from omdbapi.com (free keys: 1,000 requests a day). The key travels in the query
string, as OMDb takes it.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | - | yes | the OMDb key |
| `api` | string | `https://www.omdbapi.com/` | no | the API's address |

No language: OMDb does not translate, and the `language` arguments are ignored.

## Capabilities

| Contract | |
|---|---|
| `SearchInterface` | films, series |
| `LookupInterface` | imdb, for films, series and episodes |
| `EpisodesInterface` | a series named by its IMDb number |
| `AvailabilityInterface` | no |
| images, credits | yes; a key is needed |

`attribution`: `Data from OMDb (omdbapi.com), CC BY-NC 4.0`.

## Behaviour

**OMDb's answers.** `"N/A"` is read as nothing. `"Response": "False"` with status 200 is "not found"
(`null`, or `[]`) when its `Error` says "not found" or "Incorrect IMDb ID"; any other `Error` is a
`ProviderException`.

> [!WARNING]
> Known defect: `"Error": "Too many results."` - OMDb's answer to a search on a very common word -
> is a `ProviderException` (`OMDb answered an error for GET /. Too many results.`, seen on a
> recorded answer in the harness, 2026-10-26), where the README says only a wrong key or the daily
> limit are errors, and a search with nothing to show is expected to be `[]`.

**Search.** OMDb's first page: ten answers at most. Each is a short record: IMDb number, title,
year, type, poster. Episodes are dropped.

**A record.**

| Field | Read from |
|---|---|
| `type` | `Type` (`movie`, `series`, `episode`); another type than the ref's is `null` |
| `title`, `year` | `Title`; the first four digits of `Year` (`2011-2019` gives 2011) |
| `released` | `Released` (`31 Mar 1999`, or `Y-m-d`) |
| `runtime` | `Runtime` (`136 min`) |
| `overview` | `Plot`, the full one |
| `genres`, `countries`, `languages` | `Genre`, `Country`, `Language`, split on commas: names, not codes |
| `identifiers` | the IMDb number only |
| `images` | `Poster`, as `Poster` |
| `credits` | `Director`, `Writer`, `Actors`, split on commas: directors, writers (a part in parentheses becomes the job: `Mark Frost (creator)`), cast in that order; no identifiers |
| `ratings` | `Ratings`: Internet Movie Database as `imdb` (`8.7/10`, with `imdbVotes`), Rotten Tomatoes as `rottentomatoes` (`83%`: 83 out of 100), Metacritic as `metacritic` (`73/100`) |
| `url` | `https://www.imdb.com/title/<IMDb number>/` |
| `series`, `season`, `number` | an episode: `seriesID`, `Season`, `Episode`; a series: `season` is `totalSeasons` |

**A season.** One call; each episode carries its IMDb number, title, release date, IMDb rating (no
votes), its IMDb page, `series` the series' IMDb number, its season and number. A series named by
another register than IMDb is a `NotSupportedException`.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnifilm\Model\WorkRef;
use Omnifilm\Omdb\OmdbFactory;
use Symfony\Component\HttpClient\HttpClient;

$omdb = (new OmdbFactory(HttpClient::create()))->create(['api_key' => getenv('OMDB_KEY')]);

$matrix = $omdb->work(WorkRef::imdb('tt0133093'));
foreach ($matrix->ratings as $rating) {
    printf("%-15s %s/%s%s\n", $rating->source, $rating->value, $rating->scale, null !== $rating->votes ? ' ('.$rating->votes.' votes)' : '');
}
```

Run in the core's harness on 2026-10-26 with the HTTP client replaced by a `RecordedHttpClient`
answering the package's test answer (written after OMDb's documented example; no key was used):

```
imdb            8.7/10 (2000000 votes)
rottentomatoes  83/100
metacritic      73/100
```

The whole record read from that answer - each field of the `Work`, then the call made:

```
The Matrix / -, 1999, 1999-03-31, 136 min, season -
  ids {"imdb":"tt0133093"}
  genres ["Action","Sci-Fi"] countries ["United States","Australia"] languages ["English"]
  director: Lana Wachowski
  director: Lilly Wachowski
  writer: Lilly Wachowski
  writer: Lana Wachowski
  cast: Keanu Reeves #0
  cast: Laurence Fishburne #1
  poster https://m.media-amazon.com/images/M/example-poster.jpg
  rating imdb 8.7/10 (2000000)
  rating rottentomatoes 83/100
  rating metacritic 73/100
  https://www.imdb.com/title/tt0133093/
  > GET https://www.omdbapi.com/ {"apikey":"example-key","r":"json","i":"tt0133093","plot":"full"}
```

In Symfony:

```yaml
services:
    Omnifilm\Omdb\OmdbFactory:
        arguments: ['@http_client']
    Omnifilm\Registry:
        arguments:
            $factories: ['@Omnifilm\Omdb\OmdbFactory']
            $config:
                scores: { factory: omdb, options: { api_key: '%env(OMDB_KEY)%' } }
```

## Errors

Seen in the harness on 2026-10-26, on recorded answers:

```
omdb not found     NULL
omdb too many      ProviderException: OMDb answered an error for GET /. Too many results.
omdb bad key       ProviderException: OMDb answered 401 for GET /. {"Response":"False","Error":"Invalid API key!"}
omdb limit         ProviderException: OMDb answered 401 for GET /. {"Response":"False","Error":"Request limit reached!"}
omdb html 503      ProviderException: OMDb answered 503 for GET /. Service Unavailable
omdb 200 html      ProviderException: OMDb answered 200 for GET /. The body is not JSON.
omdb tvdb ref      NotSupportedException: OMDb does not find a series by its tvdb identifier.
```

A missing `api_key` is an `InvalidConfigException` when the provider is built.

> [!WARNING]
> Known defect: a network error's `UnreachableException` quotes the URL with its query string, the
> key included (`... for "https://www.omdbapi.com/?apikey=SECRET-KEY&r=json&i=tt0133093&plot=full".`,
> seen in a container with no network, 2026-10-26), where the family keeps keys out of messages.
> Keep these messages out of pages and shared logs.

## Verified, and not

Tested offline (`Tests/OmdbProviderTest.php`, in the core's harness, PHP 8.4): a film with its
three scores, a series with its creators and a season, a search keeping films and series, not
found as `null` and a wrong key or a limit reached as errors, only IMDb numbers known. The answers
are shaped after OMDb's documented example and written by hand. `Tests/BareTest.php` loads every
class with the package's requirements alone.

Not verified: no OMDb key was used; nothing was called on omdbapi.com.

## Responsibility

An independent, unofficial client, affiliated with OMDb in no way. OMDb's content is licensed
[CC BY-NC 4.0](https://creativecommons.org/licenses/by-nc/4.0/) and its
[terms](https://www.omdbapi.com/legal.htm) allow personal, non-commercial use only: whoever runs it
credits OMDb and complies with them (see the README).
