<?php

namespace Omnilex\Judilibre\Tests;

use Omnilex\Auth\Piste;
use Omnilex\Exception\AuthenticationException;
use Omnilex\Exception\InvalidConfigException;
use Omnilex\Exception\NotSupportedException;
use Omnilex\Exception\RateLimitedException;
use Omnilex\Exception\UnavailableException;
use Omnilex\Judilibre\JudilibreSource;
use Omnilex\Judilibre\JudilibreSourceFactory;
use Omnilex\Model\Capabilities;
use Omnilex\Model\Citation;
use Omnilex\Model\Identifier;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use Omnilex\Model\Relation;
use Omnilex\Model\Scheme;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * No PISTE credentials were at hand: nothing here was checked against the
 * real service. The fixtures are the examples of the API's own OpenAPI
 * description (JUDILIBRE-public.json, version 1.2.5, read on 2026-10-04 at
 * github.com/cour-de-cassation/judilibre-search): search.json, decision.json,
 * export.json, taxonomy-publication.json, transactionalhistory.json.
 * decision-links.json is that same decision with the links the description
 * documents (visa, contested, rapprochements) filled in after their schemas
 * (textLink, decisionLink): composed, not an answer of the service.
 */
final class JudilibreSourceTest extends TestCase
{
    private const ID = '5fca7d162a251e6bf9c78514';

    /** @var list<array{path: string, query: array<string, list<string>>, headers: array<string, string>}> */
    private array $calls = [];

    private function source(array $options = [], ?string $decision = 'decision.json'): JudilibreSource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($decision): MockResponse {
            if (str_ends_with((string) parse_url($url, \PHP_URL_HOST), 'oauth.piste.gouv.fr')) {
                $this->calls[] = ['path' => $url, 'query' => [], 'headers' => []];

                return new MockResponse('{"access_token":"piste-token","token_type":"Bearer","expires_in":3600,"scope":"openid"}');
            }
            $query = [];
            foreach (array_filter(explode('&', (string) parse_url($url, \PHP_URL_QUERY))) as $pair) {
                [$key, $value] = explode('=', $pair, 2) + [1 => ''];
                $query[rawurldecode($key)][] = rawurldecode($value);
            }
            $headers = [];
            foreach ($options['normalized_headers'] as $name => $values) {
                $headers[$name] = substr($values[0], \strlen($name) + 2);
            }
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = ['path' => $path, 'query' => $query, 'headers' => $headers];
            $fixture = match (basename($path)) {
                'search' => 'search.json',
                'export' => 'export.json',
                'taxonomy' => 'taxonomy-publication.json',
                'transactionalhistory' => 'transactionalhistory.json',
                'decision' => self::ID === ($query['id'][0] ?? null) ? $decision : null,
                default => null,
            };

            return null === $fixture ? new MockResponse('{"error":"Décision introuvable."}', ['http_code' => 404]) : new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });

        return (new JudilibreSourceFactory($http))->create($options + ['client_id' => 'my-client', 'client_secret' => 'my-secret', 'throttle' => 0]);
    }

    public function testWhatItDoes(): void
    {
        $source = $this->source();

        self::assertSame('judilibre', $source->getName());
        self::assertSame(['search', 'decision', 'citations', 'recent'], Capabilities::operations($source));
        $capabilities = $source->capabilities();
        self::assertSame([Kind::DECISION], $capabilities->kinds);
        self::assertTrue($capabilities->pseudonymised);
        self::assertSame(50, $capabilities->pageSize);
        self::assertFalse($capabilities->filters('at'));
        self::assertFalse($capabilities->filters('title'));
    }

    public function testASearchWithFiltersSignedThroughPiste(): void
    {
        $results = $this->source()->search(new Query(
            text: 'expropriation',
            kind: Kind::DECISION,
            jurisdictions: ['cc', 'ca_paris'],
            from: '1970-01-01',
            to: '2021-01-01',
            subjects: ['expropriation'],
            types: ['arret', 'qpc'],
            limit: 80,
        ));

        self::assertSame(Piste::TOKEN_URI, $this->calls[0]['path'], 'the token first');
        $call = $this->calls[1];
        self::assertSame('/cassation/judilibre/v1.0/search', $call['path']);
        self::assertSame('Bearer piste-token', $call['headers']['authorization']);
        self::assertSame('application/json', $call['headers']['accept']);
        self::assertSame([
            'query' => ['expropriation'],
            'operator' => ['and'],
            'jurisdiction' => ['cc'],
            'location' => ['ca_paris'],
            'type' => ['arret', 'qpc'],
            'theme' => ['expropriation'],
            'date_start' => ['1970-01-01'],
            'date_end' => ['2021-01-01'],
            'sort' => ['scorepub'],
            'order' => ['desc'],
            'page_size' => ['50'],
            'page' => ['0'],
        ], $call['query'], 'a list repeats its key; a seat is a location; 50 a page at most');

        self::assertSame(341, $results->total);
        self::assertSame('1', $results->next);
        self::assertCount(1, $results, 'the example\'s second result is empty: left out');
        $first = $results->items[0];
        self::assertSame(self::ID, $first->id);
        self::assertSame(Kind::DECISION, $first->kind);
        self::assertSame('Cour de cassation, civ3, 20/12/2018, n° 17-18.194', $first->title);
        self::assertSame(['ecli:ECLI:FR:CCASS:2018:C301117', 'pourvoi:17-18.194', 'pourvoi:16-21.165'], $first->identifiers->keys());
        self::assertSame('2018-12-20', $first->date->format('Y-m-d'));
        self::assertSame('arret', $first->type);
        self::assertSame('17-18.194', $first->number);
        self::assertSame(['Cour de cassation', 'cc', 'FR', 'civ3', 'fs'], [$first->court->name, $first->court->code, $first->court->country, $first->court->chamber, $first->court->formation]);
        self::assertStringStartsWith('Le titulaire d\'une autorisation temporaire d\'occupation', $first->summary);
    }

    public function testTheNextPageACaseNumberAndAnExactExpression(): void
    {
        $source = $this->source();
        $source->search(new Query(text: 'expropriation', cursor: '1', sort: Query::OLDEST));
        $query = end($this->calls)['query'];
        self::assertSame(['1', 'date', 'asc'], [$query['page'][0], $query['sort'][0], $query['order'][0]]);

        $source->search(new Query(number: 'n° C 17-18.194'));
        $query = end($this->calls)['query'];
        self::assertSame(['17-18.194', 'exact'], [$query['query'][0], $query['operator'][0]], 'a pourvoi normalised, searched exactly');

        $source->search(new Query(text: '"autorisation temporaire d\'occupation"'));
        $query = end($this->calls)['query'];
        self::assertSame(['autorisation temporaire d\'occupation', 'exact'], [$query['query'][0], $query['operator'][0]]);
    }

    public function testWithoutATextTheFiltersListByDate(): void
    {
        $results = $this->source()->search(new Query(types: ['arret', 'qpc'], from: '1970-01-01', to: '2021-01-01', limit: 10));

        $call = end($this->calls);
        self::assertSame('/cassation/judilibre/v1.0/export', $call['path'], '/search answers nothing to an empty query');
        self::assertSame(['type' => ['arret', 'qpc'], 'date_start' => ['1970-01-01'], 'date_end' => ['2021-01-01'], 'date_type' => ['creation'], 'order' => ['desc'], 'batch_size' => ['10'], 'batch' => ['0'], 'abridged' => ['true']], $call['query']);
        self::assertSame(341, $results->total);
        self::assertSame('1', $results->next);
        self::assertSame(self::ID, $results->items[0]->id);
    }

    public function testWhatItCannotFilterIsRefused(): void
    {
        $source = $this->source();
        foreach ([new Query(text: 'bail', at: '2020-01-01'), new Query(title: 'bail'), new Query(text: 'bail', kind: Kind::TEXT), new Query(text: 'bail', number: '17-18.194')] as $query) {
            try {
                $source->search($query);
                self::fail('A criterion was dropped: '.implode(', ', $query->criteria()));
            } catch (NotSupportedException) {
            }
        }
        try {
            $source->recent(new \DateTimeImmutable('2021-05-13'), new Query(text: 'bail'));
            self::fail();
        } catch (NotSupportedException $e) {
            self::assertStringContainsString('what is new is listed by filters', $e->getMessage());
        }
        self::assertSame([], $this->calls, 'refused before any call, the token included');
    }

    public function testOneDecisionWhole(): void
    {
        $decision = $this->source()->decision(self::ID);

        $call = end($this->calls);
        self::assertSame('/cassation/judilibre/v1.0/decision', $call['path']);
        self::assertSame(['id' => [self::ID]], $call['query']);
        self::assertSame(self::ID, $decision->id);
        self::assertSame('Cour de cassation, civ3, 20/12/2018, n° 17-18.194', $decision->title);
        self::assertSame('ECLI:FR:CCASS:2018:C301117', $decision->ecli());
        self::assertSame(['17-18.194', '16-21.165'], $decision->identifiers->values(Scheme::POURVOI));
        self::assertSame('17-18.194', $decision->number);
        self::assertSame(['17-18.194', '16-21.165'], $decision->numbers);
        self::assertSame('2018-12-20', $decision->date->format('Y-m-d'));
        self::assertSame('2018-12-26', $decision->updatedOn->format('Y-m-d'));
        self::assertSame('arret', $decision->type);
        self::assertSame('rejet', $decision->solution);
        self::assertSame('c, b', $decision->publication);
        self::assertSame('Expropriation pour cause d\'utilité publique', $decision->subjects[0]);
        self::assertCount(6, $decision->subjects);
        self::assertStringContainsString('COUR DE CASSATION', $decision->content);
        self::assertStringNotContainsString("\r", $decision->content);
        self::assertSame(['start' => 2309, 'end' => 4834], $decision->raw['zones']['motivations'][0], 'the zones of the text, as Judilibre delimits them');
        self::assertArrayNotHasKey('text', $decision->raw);
        self::assertSame([], $decision->citations);
        self::assertSame('fr', $decision->language);
    }

    public function testADecisionIsPseudonymisedAndStaysSo(): void
    {
        $decision = $this->source()->decision(self::ID);

        self::assertTrue($decision->pseudonymised);
        // The model has nowhere to put a party's name back: no property for the parties, none for the persons.
        foreach (array_keys(get_object_vars($decision)) as $property) {
            self::assertDoesNotMatchRegularExpression('~part(y|ies)|person|name|demandeur|defendeur~i', $property);
        }
    }

    public function testTheTextsItAppliesAndTheCaseLawAroundIt(): void
    {
        $citations = $this->source([], 'decision-links.json')->citations(self::ID);

        self::assertSame([Relation::APPLIES, Relation::APPLIES, Relation::CONTESTS, Relation::RELATED], array_map(static fn (Citation $c) => $c->relation, $citations));
        [$article, $bare, $contested, $related] = $citations;
        self::assertSame('Article L. 321-1 du code de l\'expropriation pour cause d\'utilité publique', $article->target->title);
        self::assertSame(Kind::ARTICLE, $article->target->kind, 'the link leads to an article of Légifrance');
        self::assertSame('LEGIARTI000029733417', $article->target->id);
        self::assertSame('legiarti:LEGIARTI000029733417', $article->target->identifiers->keys()[0]);
        self::assertSame(Kind::TEXT, $bare->target->kind);
        self::assertSame('71', $bare->target->id);
        self::assertNull($bare->target->url);
        self::assertSame(Kind::DECISION, $contested->target->kind);
        self::assertSame('Cour d\'appel de Paris, 23 mars 2017', $contested->target->title);
        self::assertSame('2017-03-23', $contested->target->date->format('Y-m-d'));
        self::assertSame('Cour d\'appel de Paris', $contested->target->court->location);
        self::assertCount(0, $contested->target->identifiers, 'a court of appeal\'s case number is no pourvoi');
        self::assertSame('pourvoi:04-70.165', $related->target->identifiers->keys()[0]);
        self::assertSame('Sur l\'indemnisation du titulaire d\'une autorisation d\'occupation temporaire', $related->note);

        self::assertCount(2, $this->source([], 'decision-links.json')->citations(self::ID, 2));
        self::assertSame([], $this->source()->citations('000000000000000000000000'), 'an unknown decision has none');
    }

    public function testItReadsItsOwnIdentifiersOnly(): void
    {
        self::assertNull($this->source()->decision('000000000000000000000000'), 'unknown is null');

        foreach (['ECLI:FR:CCASS:2018:C301117', '17-18.194', Identifier::ecli('ECLI:FR:CCASS:2018:C301117')] as $id) {
            try {
                $this->source()->decision($id);
                self::fail();
            } catch (NotSupportedException $e) {
                self::assertStringContainsString('search for an ECLI or a case number with Query::$number', $e->getMessage());
            }
        }
    }

    public function testWhatIsNewSinceADate(): void
    {
        $results = $this->source()->recent(new \DateTimeImmutable('2021-05-13'), new Query(jurisdictions: ['ca'], limit: 5000));

        $call = end($this->calls);
        self::assertSame('/cassation/judilibre/v1.0/export', $call['path']);
        self::assertSame(['jurisdiction' => ['ca'], 'date_start' => ['2021-05-13'], 'date_type' => ['update'], 'order' => ['desc'], 'batch_size' => ['1000'], 'batch' => ['0'], 'abridged' => ['true']], $call['query'], 'by the date of the last update, newest first, a thousand a batch at most');
        self::assertSame('1', $results->next);
        self::assertSame(self::ID, $results->items[0]->id);

        $this->source(['recent' => 'creation'])->recent(new \DateTimeImmutable('2021-05-13'), new Query(cursor: '3'));
        $call = end($this->calls);
        self::assertSame(['creation', '3'], [$call['query']['date_type'][0], $call['query']['batch'][0]]);
    }

    public function testTheTaxonomyGivesTheKeysTheirLabels(): void
    {
        $terms = $this->source()->taxonomy('publication', 'cc');

        self::assertSame(['b' => 'Publié au Bulletin', 'l' => 'Publié aux Lettres de chambre', 'r' => 'Publié au Rapport', 'c' => 'Communiqué de presse'], $terms);
        self::assertSame(['id' => ['publication'], 'context_value' => ['cc']], end($this->calls)['query']);
    }

    public function testTheChangesOfTheBaseSayWhatToDelete(): void
    {
        $source = $this->source();
        $changes = $source->changes(new \DateTimeImmutable('2021-05-13 08:00:00', new \DateTimeZone('Europe/Paris')), null, 10);

        self::assertSame(['date' => ['2021-05-13T06:00:00Z'], 'page_size' => ['10']], end($this->calls)['query']);
        self::assertCount(10, $changes['transactions']);
        self::assertSame(['id' => '673ded6e5559d27e30e40f67', 'action' => 'created'], \array_slice($changes['transactions'][0], 0, 2));
        self::assertSame('2025-01-15T14:50:10+00:00', $changes['transactions'][0]['date']->format(\DATE_ATOM));
        self::assertSame(834, $changes['total']);
        self::assertSame('date=2021-05-13T06%3A00%3A00Z&page_size=10&from_id=1736952610185%269', $changes['next']);

        $source->changes(new \DateTimeImmutable('2021-05-13'), $changes['next']);
        self::assertSame(['date' => ['2021-05-13T06:00:00Z'], 'page_size' => ['10'], 'from_id' => ['1736952610185&9']], end($this->calls)['query'], 'the next page is the query string the API handed');
    }

    public function testTheSandboxAndTheApiKey(): void
    {
        $this->source(['sandbox' => true])->decision(self::ID);
        self::assertSame(Piste::SANDBOX_TOKEN_URI, $this->calls[0]['path']);

        $this->calls = [];
        $source = $this->source(['client_id' => null, 'client_secret' => null, 'key_id' => 'my-key', 'resolve_references' => true]);
        $source->decision(self::ID);
        self::assertCount(1, $this->calls, 'no token asked');
        self::assertSame('my-key', $this->calls[0]['headers']['keyid']);
        self::assertArrayNotHasKey('authorization', $this->calls[0]['headers']);
        self::assertSame(['true'], $this->calls[0]['query']['resolve_references']);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "judilibre" source needs: client_id and client_secret, or key_id.');
        (new JudilibreSourceFactory(new MockHttpClient()))->create();
    }

    public function testRefusedBlockedOrSlowedIsNeverNotFound(): void
    {
        $token = static fn () => new MockResponse('{"access_token":"t","expires_in":3600}');
        $source = (new JudilibreSourceFactory(new MockHttpClient([
            $token(),
            new MockResponse('', ['http_code' => 429]),
            new MockResponse('', ['http_code' => 423]),
            new MockResponse('', ['http_code' => 403]),
            new MockResponse('', ['http_code' => 416]),
        ])))->create(['client_id' => 'id', 'client_secret' => 'secret', 'throttle' => 0]);

        try {
            $source->decision(self::ID);
            self::fail();
        } catch (RateLimitedException $e) {
            self::assertSame('[judilibre] rate limited', $e->getMessage());
        }
        try {
            $source->decision(self::ID);
            self::fail();
        } catch (UnavailableException $e) {
            self::assertSame(423, $e->status, 'access blocked after a suspect activity');
        }
        try {
            $source->recent(new \DateTimeImmutable('2021-05-13'));
            self::fail();
        } catch (AuthenticationException $e) {
            self::assertSame(403, $e->status);
        }
        $this->expectException(\Omnilex\Exception\ProviderException::class);
        $source->search(new Query(text: 'bail', cursor: '500'));
    }
}
