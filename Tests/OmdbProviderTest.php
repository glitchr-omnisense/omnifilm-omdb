<?php

namespace Omnifilm\Omdb\Tests;

use Omnifilm\Exception\NotSupportedException;
use Omnifilm\Exception\ProviderException;
use Omnifilm\Model\Credit;
use Omnifilm\Model\Query;
use Omnifilm\Model\Rating;
use Omnifilm\Model\Role;
use Omnifilm\Model\Work;
use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\Omdb\OmdbFactory;
use Omnifilm\Omdb\OmdbProvider;
use Omnifilm\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * OMDb's answers shaped after the example of its documentation
 * (omdbapi.com), values written by hand: no key was used, nothing here was
 * captured from OMDb.
 */
final class OmdbProviderTest extends TestCase
{
    public function testAFilmByItsImdbNumberWithItsThreeScores(): void
    {
        $http = self::http();
        $work = self::provider($http)->work(WorkRef::imdb('tt0133093'));

        self::assertNotNull($work);
        self::assertSame(['The Matrix', 1999, '1999-03-31', 136], [$work->title, $work->year, $work->released?->format('Y-m-d'), $work->runtime]);
        self::assertSame([['Action', 'Sci-Fi'], ['United States', 'Australia'], ['English']], [$work->genres, $work->countries, $work->languages]);
        self::assertSame(['Lana Wachowski', 'Lilly Wachowski'], array_map(static fn (Credit $c) => $c->name, $work->credits(Role::Director)));
        self::assertSame([['imdb', 8.7, 10.0, 2000000], ['rottentomatoes', 83.0, 100.0, null], ['metacritic', 73.0, 100.0, null]], array_map(static fn (Rating $r) => [$r->source, $r->value, $r->scale, $r->votes], $work->ratings));
        self::assertSame('https://m.media-amazon.com/images/M/example-poster.jpg', $work->image()?->url);
        self::assertSame('https://www.imdb.com/title/tt0133093/', $work->url);
        $query = $http->requests[0]['options']['query'] ?? [];
        self::assertIsArray($query);
        self::assertSame(['apikey' => 'not-a-real-omdb-key', 'r' => 'json', 'i' => 'tt0133093', 'plot' => 'full'], $query);
    }

    public function testASeriesItsCreatorsAndASeason(): void
    {
        $provider = self::provider(self::http());
        $series = $provider->work(WorkRef::imdb('tt0098936', WorkType::Series));

        self::assertNotNull($series);
        self::assertSame([1990, 2, []], [$series->year, $series->season, $series->images]);
        self::assertSame([['Mark Frost', 'creator'], ['David Lynch', 'creator']], array_map(static fn (Credit $c) => [$c->name, $c->job], $series->credits(Role::Writer)));
        self::assertSame([], $series->credits(Role::Director), '"N/A" is nothing');
        self::assertNull($provider->work(WorkRef::imdb('tt0098936')), 'a series asked for as a film');

        $episodes = $provider->episodes(WorkRef::imdb('tt0098936', WorkType::Series), 1);
        self::assertSame([[1, 'Pilot', '1990-04-08', 1], [2, 'Traces to Nowhere', '1990-04-12', 0]], array_map(static fn (Work $w) => [$w->number, $w->title, $w->released?->format('Y-m-d'), \count($w->ratings)], $episodes));
        self::assertSame('tt0098936', $episodes[0]->series?->get('imdb'));
    }

    public function testASearchKeepsFilmsAndSeries(): void
    {
        $works = self::provider(self::http())->search(new Query('matrix'));

        self::assertSame(['The Matrix', 'The Matrix Reloaded'], array_map(static fn (Work $w) => $w->title, $works));
        self::assertSame([], $works[1]->images);
    }

    public function testNotFoundIsNullAndAWrongKeyAnError(): void
    {
        self::assertNull(self::provider(self::http())->work(WorkRef::imdb('tt0000000')));

        $http = RecordedHttpClient::routes(['~~' => [200, '{"Response":"False","Error":"Request limit reached!"}']]);
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Request limit reached!');
        self::provider($http)->work(WorkRef::imdb('tt0133093'));
    }

    public function testOnlyImdbNumbersAreKnown(): void
    {
        $this->expectException(NotSupportedException::class);
        self::provider(self::http())->work(WorkRef::tmdb(603));
    }

    private static function http(): RecordedHttpClient
    {
        $f = '@'.__DIR__.'/Fixtures/';

        return RecordedHttpClient::routes([
            '~[?&]i=tt0098936&Season=1~' => [200, $f.'season.json'],
            '~[?&]i=tt0133093~' => [200, $f.'matrix.json'],
            '~[?&]i=tt0098936~' => [200, $f.'series.json'],
            '~[?&]s=matrix~' => [200, $f.'search.json'],
            '~~' => [200, '{"Response":"False","Error":"Incorrect IMDb ID."}'],
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function provider(RecordedHttpClient $http, array $options = []): OmdbProvider
    {
        $provider = (new OmdbFactory($http))->create($options + ['api_key' => 'not-a-real-omdb-key']);
        self::assertInstanceOf(OmdbProvider::class, $provider);

        return $provider;
    }
}
