<?php

namespace Omnilex\Judilibre;

use Omnilex\Auth\TokenProviderInterface;
use Omnilex\Exception\NotSupportedException;
use Omnilex\Model\Capabilities;
use Omnilex\Model\Citation;
use Omnilex\Model\Court;
use Omnilex\Model\Decision;
use Omnilex\Model\Identifier;
use Omnilex\Model\Identifiers;
use Omnilex\Model\Kind;
use Omnilex\Model\Query;
use Omnilex\Model\Reference;
use Omnilex\Model\Relation;
use Omnilex\Model\Results;
use Omnilex\Model\Scheme;
use Omnilex\Source\CitationsInterface;
use Omnilex\Source\DecisionReaderInterface;
use Omnilex\Source\HttpSource;
use Omnilex\Source\RecentInterface;
use Omnilex\Source\SearchInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Judilibre, the Cour de cassation's open data of the judicial courts'
 * decisions, through PISTE (the API's OpenAPI description, version 1.2.5):
 *
 *   GET /search                 full-text search with filters, 50 a page, 10 000 at most
 *   GET /decision               one decision, whole
 *   GET /export                 batches of decisions by date, for what is new
 *   GET /taxonomy               the keys the filters take, with their labels
 *   GET /transactionalhistory   what was created, updated or deleted since a date
 *
 * The decisions are pseudonymised by the Cour de cassation before they are
 * published. They are given as they are: nothing here tries to put a name
 * back on a person, by cross-reference or otherwise, and an application
 * that shows them must not either (the re-use conditions forbid it). When
 * Judilibre withdraws a decision - changes() says so - the application
 * must drop its copy.
 */
final class JudilibreSource extends HttpSource implements SearchInterface, DecisionReaderInterface, CitationsInterface, RecentInterface
{
    public const BASE_URI = 'https://api.piste.gouv.fr/cassation/judilibre/v1.0';
    public const SANDBOX_URI = 'https://sandbox-api.piste.gouv.fr/cassation/judilibre/v1.0';

    /** The most results /search gives per page. */
    public const MAX = 50;
    /** The most decisions /export gives per batch. */
    public const BATCH_MAX = 1000;

    /** The kinds of courts /search and /export filter on; anything else is a seat (ca_paris, tj33063). */
    private const JURISDICTIONS = ['cc', 'ca', 'tj', 'tcom', 'cph'];

    /** The labels the API's description gives for the kinds of courts. */
    private const COURTS = ['cc' => 'Cour de cassation', 'ca' => 'Cour d\'appel'];

    /**
     * @param string                $recent           the date recent() reads: "update" (created or updated in the base) or "creation"
     * @param bool                  $resolve          ask for labels in place of keys (resolve_references)
     * @param array<string, string> $headers          the KeyId header, when the application signs with its PISTE key
     */
    public function __construct(
        HttpClientInterface $http,
        string $baseUri = self::BASE_URI,
        ?TokenProviderInterface $auth = null,
        array $headers = [],
        float $throttle = 0.5,
        private readonly string $recent = 'update',
        private readonly bool $resolve = false,
    ) {
        if (!\in_array($recent, ['update', 'creation'], true)) {
            throw new \InvalidArgumentException(\sprintf('recent() reads "update" or "creation", not "%s".', $recent));
        }
        parent::__construct($http, $baseUri, $headers, $throttle, $auth);
    }

    public function getName(): string
    {
        return 'judilibre';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            kinds: [Kind::DECISION],
            identifiers: [],
            criteria: ['text', 'number', 'kind', 'jurisdictions', 'from', 'to', 'subjects', 'types'],
            jurisdictions: ['FR'],
            pageSize: self::MAX,
            pseudonymised: true,
        );
    }

    public function search(Query $query): Results
    {
        $this->accept($query);
        self::decisionsOnly($query, $this->getName());
        $text = $query->text ?? $query->number;
        if (null === $text || '' === trim($text)) {
            // /search answers nothing to an empty query: without text, the filters go to /export.
            return $this->export($query, $query->from, $query->to, 'creation', Query::OLDEST === $query->sort ? 'asc' : 'desc');
        }
        if (null !== $query->text && null !== $query->number) {
            throw NotSupportedException::criterion($this->getName(), 'number', 'a case number is searched as the text, not along with one');
        }

        $exact = null !== $query->number || preg_match('~^".+"$~su', trim($text));
        $limit = max(1, min($query->limit, self::MAX));
        $page = max(0, (int) $query->cursor);
        $data = $this->getJson('search', [
            'query' => null !== $query->number ? self::number($query->number) : trim(trim($text), '"'),
            'operator' => $exact ? 'exact' : 'and',
            ...$this->filters($query),
            'date_start' => $query->from?->format('Y-m-d'),
            'date_end' => $query->to?->format('Y-m-d'),
            'sort' => Query::RELEVANCE === $query->sort ? 'scorepub' : 'date',
            'order' => Query::OLDEST === $query->sort ? 'asc' : 'desc',
            'page_size' => $limit,
            'page' => $page,
            'resolve_references' => $this->resolve ? true : null,
        ]) ?? [];

        return new Results(
            array_values(array_map($this->toReference(...), array_filter((array) ($data['results'] ?? []), static fn ($r) => \is_array($r) && isset($r['id'])))),
            isset($data['next_page']) && '' !== $data['next_page'] ? (string) ($page + 1) : null,
            isset($data['total']) ? (int) $data['total'] : null,
        );
    }

    public function recent(\DateTimeInterface $since, ?Query $query = null): Results
    {
        $query ??= new Query();
        $this->accept($query, ['from', 'to']);
        self::decisionsOnly($query, $this->getName());
        if (null !== $query->text || null !== $query->number) {
            throw NotSupportedException::criterion($this->getName(), 'text', 'what is new is listed by filters; search() takes a text and dates');
        }

        return $this->export($query, Query::day($since), null, $this->recent, 'desc');
    }

    public function decision(Identifier|string $id): ?Decision
    {
        $data = $this->getJson('decision', ['id' => $this->id($id), 'resolve_references' => $this->resolve ? true : null]);

        return null === $data || !isset($data['id']) ? null : $this->toDecision($data);
    }

    public function citations(Identifier|string $id, int $limit = 100): array
    {
        return \array_slice($this->decision($id)?->citations ?? [], 0, max(1, $limit));
    }

    /**
     * The terms of one of Judilibre's lists, key => label: "jurisdiction",
     * "chamber", "formation", "type", "theme", "publication", "solution",
     * "location"... ($context: "cc", "ca", "tj" for the lists that depend on
     * the kind of court). What Query::$jurisdictions, $types and $subjects
     * take, and what labels the keys the decisions carry.
     *
     * @return array<string, string>
     */
    public function taxonomy(string $id, ?string $context = null): array
    {
        $data = $this->getJson('taxonomy', ['id' => $id, 'context_value' => $context]);
        $result = $data['result'] ?? [];

        return \is_array($result) ? array_map('strval', array_filter($result, 'is_scalar')) : [];
    }

    /**
     * What happened in the base since that moment, oldest first: each
     * decision created, updated or deleted. A deleted decision must be
     * deleted by whoever kept a copy.
     *
     * @param string|null $cursor the "next" of the page before
     *
     * @return array{transactions: list<array{id: string, action: string, date: ?\DateTimeImmutable}>, next: ?string, total: ?int}
     */
    public function changes(\DateTimeInterface $since, ?string $cursor = null, int $limit = 500): array
    {
        if (null !== $cursor) {
            // The API hands the next page as a ready query string, valid for a minute.
            $data = $this->getJson('transactionalhistory?'.ltrim($cursor, '?'));
        } else {
            $data = $this->getJson('transactionalhistory', ['date' => \DateTimeImmutable::createFromInterface($since)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'page_size' => max(10, min($limit, 500))]);
        }
        $transactions = [];
        foreach ((array) ($data['transactions'] ?? []) as $transaction) {
            if (\is_array($transaction) && isset($transaction['id'], $transaction['action'])) {
                $date = null;
                try {
                    $date = isset($transaction['date']) ? new \DateTimeImmutable((string) $transaction['date']) : null;
                } catch (\Exception) {
                }
                $transactions[] = ['id' => (string) $transaction['id'], 'action' => (string) $transaction['action'], 'date' => $date];
            }
        }

        return [
            'transactions' => $transactions,
            'next' => isset($data['next_page']) && '' !== $data['next_page'] ? (string) $data['next_page'] : null,
            'total' => isset($data['total']) ? (int) $data['total'] : null,
        ];
    }

    /** One batch of /export: the decisions matching the filters, by date. */
    private function export(Query $query, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, string $dateType, string $order): Results
    {
        $batch = max(0, (int) $query->cursor);
        $data = $this->getJson('export', [
            ...$this->filters($query),
            'date_start' => $from?->format('Y-m-d'),
            'date_end' => $to?->format('Y-m-d'),
            'date_type' => null !== $from || null !== $to ? $dateType : null,
            'order' => $order,
            'batch_size' => max(1, min($query->limit, self::BATCH_MAX)),
            'batch' => $batch,
            // The list is of references: the whole decisions are read one by one.
            'abridged' => true,
            'resolve_references' => $this->resolve ? true : null,
        ]) ?? [];

        return new Results(
            array_values(array_map($this->toReference(...), array_filter((array) ($data['results'] ?? []), static fn ($r) => \is_array($r) && isset($r['id'])))),
            isset($data['next_batch']) && '' !== $data['next_batch'] ? (string) ($batch + 1) : null,
            isset($data['total']) ? (int) $data['total'] : null,
        );
    }

    /**
     * The filters /search and /export share.
     *
     * @return array<string, list<string>>
     */
    private function filters(Query $query): array
    {
        $jurisdictions = $locations = [];
        foreach ($query->jurisdictions as $code) {
            $code = strtolower(trim($code));
            if (\in_array($code, self::JURISDICTIONS, true)) {
                $jurisdictions[] = $code;
            } else {
                $locations[] = $code;
            }
        }

        return array_filter([
            'jurisdiction' => $jurisdictions,
            'location' => $locations,
            'type' => array_values($query->types),
            'theme' => array_values($query->subjects),
        ]);
    }

    /** @param array<string, mixed> $data a searchResult, or an abridged decision */
    private function toReference(array $data): Reference
    {
        $court = $this->court($data);
        $highlights = [];
        foreach ((array) ($data['highlights'] ?? []) as $fragments) {
            $highlights = [...$highlights, ...array_filter((array) $fragments, 'is_string')];
        }

        return new Reference(
            kind: Kind::DECISION,
            id: (string) $data['id'],
            title: self::title($court, $data),
            identifiers: self::identifiers($data),
            date: self::day($data['decision_date'] ?? null),
            type: isset($data['type']) ? (string) $data['type'] : null,
            number: isset($data['number']) ? (string) $data['number'] : null,
            court: $court,
            summary: self::clean($data['summary'] ?? null) ?? self::plain(implode(' … ', \array_slice($highlights, 0, 3))),
            url: null,
            source: $this->getName(),
            raw: $data,
        );
    }

    /** @param array<string, mixed> $data a decisionFull */
    private function toDecision(array $data): Decision
    {
        $court = $this->court($data);
        $citations = [];
        foreach ((array) ($data['visa'] ?? []) as $text) {
            if (\is_array($text) && isset($text['title'])) {
                $citations[] = new Citation(Relation::APPLIES, $this->textLink($text));
            }
        }
        foreach ([[$data['contested'] ?? null, Relation::CONTESTS], [$data['forward'] ?? null, Relation::FOLLOWED_BY]] as [$link, $relation]) {
            if (\is_array($link) && (isset($link['title']) || isset($link['id']))) {
                $citations[] = new Citation($relation, $this->decisionLink($link));
            }
        }
        foreach ((array) ($data['rapprochements'] ?? []) as $link) {
            if (\is_array($link) && (isset($link['title']) || isset($link['id']))) {
                $citations[] = new Citation(Relation::RELATED, $this->decisionLink($link), self::clean($link['description'] ?? null));
            }
        }
        $numbers = array_values(array_unique(array_filter(array_map('strval', [$data['number'] ?? '', ...(array) ($data['numbers'] ?? [])]))));
        $solution = 'other' === ($data['solution'] ?? null) ? ($data['solution_alt'] ?? 'other') : ($data['solution'] ?? null);
        $text = isset($data['text']) ? trim(str_replace(["\r\n", "\r"], "\n", (string) $data['text'])) : null;
        $raw = $data;
        unset($raw['text'], $raw['text_highlight']);

        return new Decision(
            id: (string) $data['id'],
            title: self::title($court, $data),
            identifiers: self::identifiers($data),
            court: $court,
            date: self::day($data['decision_date'] ?? null),
            number: $numbers[0] ?? null,
            numbers: $numbers,
            type: isset($data['type']) ? (string) $data['type'] : null,
            solution: null !== $solution ? (string) $solution : null,
            publication: implode(', ', array_filter((array) ($data['publication'] ?? []), 'is_string')) ?: null,
            summary: self::clean($data['summary'] ?? null),
            subjects: array_values(array_filter((array) ($data['themes'] ?? []), 'is_string')),
            content: '' === $text ? null : $text,
            citations: $citations,
            pseudonymised: true,
            updatedOn: self::day($data['update_date'] ?? null),
            language: 'fr',
            url: null,
            source: $this->getName(),
            raw: $raw,
        );
    }

    /** @param array<string, mixed> $data */
    private function court(array $data): ?Court
    {
        $key = isset($data['jurisdiction']) ? (string) $data['jurisdiction'] : null;
        if (null === $key || '' === $key) {
            return null;
        }
        $isKey = \in_array($key, self::JURISDICTIONS, true);

        return new Court(
            // With resolve_references the API sends the label where the key was.
            $isKey ? (self::COURTS[$key] ?? $key) : $key,
            $isKey ? $key : null,
            'FR',
            isset($data['chamber']) && '' !== $data['chamber'] ? (string) $data['chamber'] : null,
            isset($data['formation']) && '' !== $data['formation'] ? (string) $data['formation'] : null,
            isset($data['location']) && '' !== $data['location'] ? (string) $data['location'] : null,
        );
    }

    /** @param array<string, mixed> $link a textLink: id, title, url */
    private function textLink(array $link): Reference
    {
        $url = isset($link['url']) && '' !== $link['url'] ? (string) $link['url'] : null;
        $identifier = null !== $url ? Identifier::parse($url) : null;

        return new Reference(
            kind: $identifier?->scheme->kind() ?? Kind::TEXT,
            id: $identifier?->value ?? (string) ($link['id'] ?? ''),
            title: (string) self::clean($link['title']),
            identifiers: Identifiers::of($identifier),
            url: $url,
            source: $this->getName(),
            raw: $link,
        );
    }

    /** @param array<string, mixed> $link a decisionLink */
    private function decisionLink(array $link): Reference
    {
        return new Reference(
            kind: Kind::DECISION,
            id: (string) ($link['id'] ?? ''),
            title: (string) (self::clean($link['title'] ?? null) ?? $link['number'] ?? $link['id'] ?? ''),
            identifiers: Identifiers::of(Identifier::tryOf(Scheme::POURVOI, isset($link['number']) ? (string) $link['number'] : null)),
            date: self::day($link['date'] ?? null),
            number: isset($link['number']) ? (string) $link['number'] : null,
            court: isset($link['jurisdiction']) && '' !== $link['jurisdiction'] ? new Court((string) $link['jurisdiction'], null, 'FR', $link['chamber'] ?? null, null, $link['location'] ?? null) : null,
            summary: self::clean($link['description'] ?? null),
            url: isset($link['url']) && '' !== $link['url'] ? (string) $link['url'] : null,
            source: $this->getName(),
            raw: $link,
        );
    }

    /** @param array<string, mixed> $data */
    private static function identifiers(array $data): Identifiers
    {
        $numbers = array_filter(array_map('strval', [$data['number'] ?? '', ...(array) ($data['numbers'] ?? [])]));

        return new Identifiers([
            Identifier::tryOf(Scheme::ECLI, isset($data['ecli']) ? (string) $data['ecli'] : null),
            // The courts below number their cases otherwise (a "RG"): only the Cour de cassation's are pourvois.
            ...array_map(static fn (string $n) => Identifier::tryOf(Scheme::POURVOI, $n), array_values($numbers)),
        ]);
    }

    /** @param array<string, mixed> $data */
    private static function title(?Court $court, array $data): string
    {
        $date = self::day($data['decision_date'] ?? null);
        $parts = array_filter([
            $court?->name,
            $court?->chamber,
            $date?->format('d/m/Y'),
            isset($data['number']) && '' !== $data['number'] ? 'n° '.$data['number'] : null,
        ]);

        return $parts ? implode(', ', $parts) : (string) $data['id'];
    }

    /** Judilibre names a decision by its own identifier: the one search() and recent() give. */
    private function id(Identifier|string $id): string
    {
        if ($id instanceof Identifier || !preg_match('~^[0-9a-f]{24}$~i', trim($id))) {
            throw NotSupportedException::identifier($this->getName(), (string) $id, 'read a decision by (it reads its own identifiers; search for an ECLI or a case number with Query::$number, then read the result)');
        }

        return strtolower(trim($id));
    }

    /** A case number as Judilibre writes it: a pourvoi normalised, anything else as given. */
    private static function number(string $number): string
    {
        return Identifier::tryOf(Scheme::POURVOI, $number)?->value ?? trim($number);
    }

    private static function clean(mixed $text): ?string
    {
        return \is_string($text) && '' !== trim($text) ? trim((string) preg_replace('~\s+~u', ' ', $text)) : null;
    }

    private static function decisionsOnly(Query $query, string $name): void
    {
        if (null !== $query->kind && Kind::DECISION !== $query->kind) {
            throw NotSupportedException::criterion($name, 'kind', 'only decisions are held');
        }
    }
}
