<?php

namespace Omnifilm\Omdb;

use Omnifilm\Capabilities;
use Omnifilm\EpisodesInterface;
use Omnifilm\Exception\NotSupportedException;
use Omnifilm\Exception\ProviderException;
use Omnifilm\Http\Data;
use Omnifilm\Http\JsonClient;
use Omnifilm\LookupInterface;
use Omnifilm\Model\Credit;
use Omnifilm\Model\Identifiers;
use Omnifilm\Model\Image;
use Omnifilm\Model\ImageKind;
use Omnifilm\Model\Query;
use Omnifilm\Model\Rating;
use Omnifilm\Model\Role;
use Omnifilm\Model\Scheme;
use Omnifilm\Model\Work;
use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\ProviderInterface;
use Omnifilm\SearchInterface;

/**
 * OMDb: works by their IMDb number, with the IMDb, Rotten Tomatoes and
 * Metacritic scores it gathers; a search by title (ten answers a page, the
 * first page only); a season's episodes. Its "N/A" is read as nothing, its
 * "Response": "False" with a 200 as not found - or as an error when it says
 * the key is wrong or the daily limit reached.
 */
final class OmdbProvider implements ProviderInterface, SearchInterface, LookupInterface, EpisodesInterface
{
    private const TYPES = ['movie' => WorkType::Movie, 'series' => WorkType::Series, 'episode' => WorkType::Episode];
    private const SCALES = ['Internet Movie Database' => 'imdb', 'Rotten Tomatoes' => 'rottentomatoes', 'Metacritic' => 'metacritic'];

    public function __construct(private readonly JsonClient $json, private readonly string $key, private readonly string $api = 'https://www.omdbapi.com/')
    {
    }

    public function getName(): string
    {
        return 'omdb';
    }

    public function getTitle(): string
    {
        return 'OMDb';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            search: true,
            lookup: true,
            episodes: true,
            types: [WorkType::Movie, WorkType::Series, WorkType::Episode],
            lookupBy: ['movie' => [Scheme::Imdb], 'series' => [Scheme::Imdb], 'episode' => [Scheme::Imdb]],
            images: true,
            credits: true,
            attribution: 'Data from OMDb (omdbapi.com), CC BY-NC 4.0',
            terms: ['https://www.omdbapi.com/legal.htm', 'https://creativecommons.org/licenses/by-nc/4.0/'],
        );
    }

    public function search(Query $query): array
    {
        $answer = $this->call(['s' => $query->text, 'type' => null === $query->type ? null : (WorkType::Series === $query->type ? 'series' : 'movie'), 'y' => $query->year, 'page' => 1]);
        $works = [];
        foreach ($answer?->list('Search') ?? [] as $result) {
            $type = self::TYPES[(string) $result->string('Type')] ?? null;
            if (null !== $type && WorkType::Episode !== $type) {
                $works[] = $this->work0($result, $type);
            }
        }

        return \array_slice($works, 0, $query->limit);
    }

    public function work(WorkRef $ref, ?string $language = null): ?Work
    {
        if (!$this->capabilities()->resolves($ref)) {
            throw NotSupportedException::ref($this->getTitle(), $ref);
        }
        $answer = $this->call(['i' => $ref->id, 'plot' => 'full']);
        $type = null === $answer ? null : (self::TYPES[(string) $answer->string('Type')] ?? null);

        return null !== $answer && $type === $ref->type ? $this->work0($answer, $type) : null;
    }

    public function episodes(WorkRef $series, int $season, ?string $language = null): array
    {
        if (Scheme::Imdb !== $series->scheme || WorkType::Series !== $series->type) {
            throw NotSupportedException::ref($this->getTitle(), $series);
        }
        $answer = $this->call(['i' => $series->id, 'Season' => $season]);
        $episodes = [];
        foreach ($answer?->list('Episodes') ?? [] as $episode) {
            $released = self::date($episode->string('Released'));
            $rating = self::na($episode->string('imdbRating'));
            $episodes[] = new Work(
                type: WorkType::Episode,
                title: (string) $episode->string('Title'),
                identifiers: Identifiers::of(['imdb' => $episode->string('imdbID')]),
                source: 'omdb',
                year: null !== $released ? (int) $released->format('Y') : null,
                released: $released,
                ratings: is_numeric($rating) ? [new Rating('imdb', (float) $rating)] : [],
                url: null === $episode->string('imdbID') ? null : 'https://www.imdb.com/title/'.$episode->string('imdbID').'/',
                series: Identifiers::of(['imdb' => $series->id]),
                season: $season,
                number: $episode->int('Episode'),
            );
        }

        return $episodes;
    }

    /** @param array<string, string|int|null> $query */
    private function call(array $query): ?Data
    {
        $answer = $this->json->get($this->api, ['apikey' => $this->key, 'r' => 'json'] + $query);
        if (null === $answer || 'False' !== $answer->string('Response')) {
            return $answer;
        }
        $error = (string) $answer->string('Error');
        if (preg_match('~not found|incorrect imdb id~i', $error)) {
            return null;
        }
        throw ProviderException::answered('OMDb', 'GET /', 'an error', $error);
    }

    private function work0(Data $data, WorkType $type): Work
    {
        $imdb = $data->string('imdbID');
        $released = self::date($data->string('Released'));
        $year = preg_match('~^\d{4}~', (string) $data->string('Year'), $m) ? (int) $m[0] : null;
        $poster = self::na($data->string('Poster'));
        $credits = [];
        foreach (['Director' => Role::Director, 'Writer' => Role::Writer, 'Actors' => Role::Cast] as $field => $role) {
            foreach (self::names($data->string($field)) as $order => $name) {
                // "Lana Wachowski, Lilly Wachowski"; a writer's part in parentheses: "Mark Frost (creator)".
                $job = preg_match('~\s*\(([^)]+)\)$~', $name, $p) ? $p[1] : null;
                $credits[] = new Credit(preg_replace('~\s*\([^)]+\)$~', '', $name) ?? $name, $role, null, Role::Cast === $role ? $order : null, null, $job);
            }
        }
        $ratings = [];
        foreach ($data->list('Ratings') as $rating) {
            $source = self::SCALES[(string) $rating->string('Source')] ?? null;
            if (null !== $source && preg_match('~^([\d.]+)\s*(?:/\s*([\d.]+)|(%))$~', (string) $rating->string('Value'), $v)) {
                $votes = 'imdb' === $source ? (int) str_replace(',', '', (string) self::na($data->string('imdbVotes'))) : 0;
                $ratings[] = new Rating($source, (float) $v[1], '%' === ($v[3] ?? null) ? 100.0 : (float) ($v[2] ?? 10), $votes > 0 ? $votes : null);
            }
        }

        return new Work(
            type: $type,
            title: (string) $data->string('Title'),
            identifiers: Identifiers::of(['imdb' => $imdb]),
            source: 'omdb',
            year: $year,
            released: $released,
            runtime: preg_match('~^(\d+)\s*min~', (string) $data->string('Runtime'), $r) ? (int) $r[1] : null,
            overview: self::na($data->string('Plot')),
            genres: self::names($data->string('Genre')),
            countries: self::names($data->string('Country')),
            languages: self::names($data->string('Language')),
            images: null === $poster ? [] : [new Image(ImageKind::Poster, $poster)],
            credits: $credits,
            ratings: $ratings,
            url: null === $imdb ? null : 'https://www.imdb.com/title/'.$imdb.'/',
            series: WorkType::Episode === $type ? Identifiers::of(['imdb' => $data->string('seriesID')]) : null,
            season: WorkType::Episode === $type ? $data->int('Season') : $data->int('totalSeasons'),
            number: WorkType::Episode === $type ? $data->int('Episode') : null,
        );
    }

    private static function na(?string $value): ?string
    {
        return null === $value || 'N/A' === $value ? null : $value;
    }

    /** @return list<string> */
    private static function names(?string $value): array
    {
        $value = self::na($value);

        return null === $value ? [] : array_values(array_unique(array_filter(array_map('trim', explode(',', $value)))));
    }

    /** "31 Mar 1999" */
    private static function date(?string $value): ?\DateTimeImmutable
    {
        $value = self::na($value);

        return null === $value ? null : (\DateTimeImmutable::createFromFormat('!d M Y', $value, new \DateTimeZone('UTC')) ?: (\DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC')) ?: null));
    }
}
