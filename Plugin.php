<?php

namespace AppLocalPlugins\TvLogos;

use App\Models\Channel;
use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class Plugin implements ChannelProcessorPluginInterface, HookablePluginInterface, PluginInterface
{
    // -------------------------------------------------------------------------
    // Self-hosted CDN (logos.austheim.app) — no GitHub API rate limits.
    // The manifest is fetched once per cache TTL and contains every logo path.
    // -------------------------------------------------------------------------
    private const DEFAULT_CDN_BASE     = 'https://tvlogos.austheim.app/countries';
    private const DEFAULT_MANIFEST_URL = 'https://tvlogos.austheim.app/logos-manifest.json';

    private const CACHE_FILE     = 'plugin-data/tv-logos/matches.json';
    private const CACHE_VERSION  = 6;   // bump forces a full cache flush on first run
    private const LOG_BATCH_SIZE = 100;

    private string $cdnBase;
    private string $manifestUrl;

    /**
     * Maps ISO 3166-1 alpha-2 country codes (+ virtual regions) to the
     * folder path under /countries/ in the dj1p/tvlogos repository.
     *
     * Nordic countries live under countries/nordic/<country>/.
     * Additional countries and virtual regions present in this fork are
     * included; they are absent from the upstream tv-logo/tv-logos plugin.
     *
     * @var array<string, string>
     */
    private const COUNTRY_FOLDERS = [
        // ── Standard countries ────────────────────────────────────────────
        'al' => 'albania',
        'ar' => 'argentina',
        'au' => 'australia',
        'at' => 'austria',
        'az' => 'azerbaijan',
        'be' => 'belgium',
        'ba' => 'bosnia-and-herzegovina',
        'br' => 'brazil',
        'bg' => 'bulgaria',
        'ca' => 'canada',
        'cl' => 'chile',
        'cr' => 'costa-rica',
        'hr' => 'croatia',
        'cz' => 'czech-republic',
        'fr' => 'france',
        'de' => 'germany',
        'gr' => 'greece',
        'hk' => 'hong-kong',
        'hu' => 'hungary',
        'in' => 'india',
        'id' => 'indonesia',
        'ie' => 'ireland',
        'il' => 'israel',
        'it' => 'italy',
        'lb' => 'lebanon',
        'lt' => 'lithuania',
        'lu' => 'luxembourg',
        'my' => 'malaysia',
        'mt' => 'malta',
        'mx' => 'mexico',
        'nl' => 'netherlands',
        'nz' => 'new-zealand',
        'ph' => 'philippines',
        'pl' => 'poland',
        'pt' => 'portugal',
        'ro' => 'romania',
        'ru' => 'russia',
        'rs' => 'serbia',
        'sg' => 'singapore',
        'sk' => 'slovakia',
        'si' => 'slovenia',
        'za' => 'south-africa',
        'kr' => 'south-korea',
        'es' => 'spain',
        'ch' => 'switzerland',
        'tr' => 'turkey',
        'ua' => 'ukraine',
        'ae' => 'united-arab-emirates',
        'gb' => 'united-kingdom',
        'us' => 'united-states',
        'th' => 'thailand',

        // ── Nordic countries (nested under countries/nordic/) ─────────────
        'dk' => 'nordic/denmark',
        'fi' => 'nordic/finland',
        'is' => 'nordic/iceland',
        'no' => 'nordic/norway',
        'se' => 'nordic/sweden',

        // ── Virtual / regional groupings ─────────────────────────────────
        'caribbean'         => 'caribbean',
        'international'     => 'international',
        'nordic'            => 'nordic',
        'world-africa'      => 'world-africa',
        'world-asia'        => 'world-asia',
        'world-europe'      => 'world-europe',
        'world-latin-america' => 'world-latin-america',
        'world-middle-east' => 'world-middle-east',
    ];

    // =========================================================================
    // Plugin entry points
    // =========================================================================

    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'health_check'  => $this->healthCheck($context),
            'enrich_logos'  => $this->enrichFromAction($payload, $context),
            default         => PluginActionResult::failure("Unsupported action [{$action}]."),
        };
    }

    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        if ($hook !== 'playlist.synced') {
            return PluginActionResult::success("Hook [{$hook}] not handled by TV Logos.");
        }

        $playlistId = (int) ($payload['playlist_id'] ?? 0);
        if ($playlistId === 0) {
            return PluginActionResult::failure('Missing playlist_id in hook payload.');
        }

        $configured  = $context->settings['default_playlist_id'] ?? null;
        $watchedIds  = array_map('intval', array_filter((array) $configured));

        if ($watchedIds === []) {
            return PluginActionResult::success('No default playlist(s) configured — skipping automatic enrichment.');
        }

        if (! in_array($playlistId, $watchedIds, true)) {
            return PluginActionResult::success("Playlist #{$playlistId} is not in the configured defaults — skipping.");
        }

        return $this->processPlaylist($playlistId, $context);
    }

    // =========================================================================
    // Actions
    // =========================================================================

    /**
     * Ping the CDN and manifest endpoint; report cache stats.
     */
    private function healthCheck(PluginExecutionContext $context): PluginActionResult
    {
        $settings    = $context->settings;
        $cdnBase     = rtrim((string) ($settings['cdn_base']     ?? self::DEFAULT_CDN_BASE),     '/');
        $manifestUrl = rtrim((string) ($settings['manifest_url'] ?? self::DEFAULT_MANIFEST_URL), '/');

        $context->info('Checking CDN reachability…');

        $cdnReachable      = false;
        $manifestReachable = false;
        $manifestTotal     = null;

        try {
            $r = Http::timeout(10)->head("{$cdnBase}/united-states/espn-us.png");
            $cdnReachable = $r->successful();
        } catch (Throwable) {}

        try {
            $r = Http::timeout(10)->get($manifestUrl);
            if ($r->successful()) {
                $manifestReachable = true;
                $manifestTotal = $r->json('total');
            }
        } catch (Throwable) {}

        $cacheEntries = 0;
        try {
            $cache        = $this->loadCache(0);
            $cacheEntries = count($cache['matches'] ?? []);
        } catch (Throwable) {}

        return PluginActionResult::success('Health check complete.', [
            'cdn_reachable'      => $cdnReachable,
            'cdn_base'           => $cdnBase,
            'manifest_reachable' => $manifestReachable,
            'manifest_url'       => $manifestUrl,
            'manifest_logos'     => $manifestTotal,
            'cached_entries'     => $cacheEntries,
            'supported_codes'    => array_keys(self::COUNTRY_FOLDERS),
        ]);
    }

    /**
     * Manual enrich_logos action — accepts per-run overrides.
     */
    private function enrichFromAction(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $playlistId = (int) ($payload['playlist_id'] ?? 0);
        if ($playlistId === 0) {
            return PluginActionResult::failure('Missing playlist_id in action payload.');
        }

        $overrides = [];
        foreach (['overwrite_existing', 'skip_vod', 'ignore_cache'] as $key) {
            if (array_key_exists($key, $payload)) {
                $overrides[$key] = (bool) $payload[$key];
            }
        }

        return $this->processPlaylist($playlistId, $context, $overrides);
    }

    // =========================================================================
    // Core enrichment
    // =========================================================================

    /**
     * @param array{overwrite_existing?: bool, skip_vod?: bool, ignore_cache?: bool} $overrides
     */
    private function processPlaylist(int $playlistId, PluginExecutionContext $context, array $overrides = []): PluginActionResult
    {
        $settings = $context->settings;

        // ── Settings ─────────────────────────────────────────────────────────
        $rawCodes        = strtolower(trim((string) ($settings['country_code']     ?? 'us')));
        $countryCodes    = array_values(array_filter(array_map('trim', explode(',', $rawCodes))));
        if ($countryCodes === []) {
            $countryCodes = ['us'];
        }
        $overwrite       = (bool) ($overrides['overwrite_existing'] ?? $settings['overwrite_existing'] ?? false);
        $skipVod         = (bool) ($overrides['skip_vod']           ?? $settings['skip_vod']           ?? true);
        $ignoreCache     = (bool) ($overrides['ignore_cache']       ?? false);
        $cacheTtlDays    = (int)  ($settings['cache_ttl_days']      ?? 7);
        $isDryRun        = $context->dryRun;
        $normConfig      = $this->buildNormalizationConfig($settings);

        $this->cdnBase     = rtrim((string) ($settings['cdn_base']     ?? self::DEFAULT_CDN_BASE),     '/');
        $this->manifestUrl = rtrim((string) ($settings['manifest_url'] ?? self::DEFAULT_MANIFEST_URL), '/');

        // ── Validate country ──────────────────────────────────────────────────
        $countryFolders = [];
        $invalidCodes   = [];
        foreach ($countryCodes as $code) {
            $folder = self::COUNTRY_FOLDERS[$code] ?? null;
            if ($folder === null) {
                $invalidCodes[] = $code;
            } else {
                $countryFolders[$code] = $folder;
            }
        }
        if ($invalidCodes !== []) {
            return PluginActionResult::failure(sprintf(
                'Unknown country code [%s]. Supported codes: %s.',
                implode(', ', $invalidCodes),
                implode(', ', array_keys(self::COUNTRY_FOLDERS))
            ));
        }
        $countryCode = implode(', ', $countryCodes);

        // ── Load cache & manifest ─────────────────────────────────────────────
        $cache        = $this->loadCache($cacheTtlDays);
        $cacheChanged = false;

        // Fetch the full manifest once per cache TTL; it covers ALL countries.
        $manifest = $this->fetchManifest($cache, $cacheChanged, $ignoreCache);

        // Build per-country indexes from the manifest.
        $countryIndexes = [];
        foreach ($countryFolders as $code => $folder) {
            [$index, $byBasename] = $this->buildCountryIndex($manifest, $folder);
            $countryIndexes[$code] = ['folder' => $folder, 'index' => $index, 'byBasename' => $byBasename];
            if ($index !== []) {
                $context->info(sprintf(
                    'Loaded %d logo entries for "%s" from manifest.',
                    count($index),
                    $folder
                ));
            } else {
                $context->info(sprintf(
                    'No manifest entries found for "%s". Falling back to CDN HEAD checks.',
                    $folder
                ));
            }
        }

        // ── Query channels ────────────────────────────────────────────────────
        $query = Channel::query()
            ->where('playlist_id', $playlistId)
            ->where('enabled', true)
            ->select(['id', 'title', 'title_custom', 'name', 'name_custom', 'logo']);

        if ($skipVod) {
            $query->where('is_vod', false);
        }

        if (! $overwrite) {
            $query->where(function ($q): void {
                $q->whereNull('logo')->orWhere('logo', '');
            });
        }

        $channels = $query->get();
        $total    = $channels->count();

        if ($total === 0) {
            return PluginActionResult::success('No channels require logo enrichment.', [
                'matched' => 0, 'skipped' => 0, 'total' => 0,
            ]);
        }

        $context->info(sprintf(
            'Processing %d channel(s) for playlist #%d [country=%s%s].',
            $total, $playlistId, $countryCode, $isDryRun ? ', dry_run' : ''
        ));

        // ── Enrich loop ───────────────────────────────────────────────────────
        $matched       = 0;
        $unmatched     = 0;
        $cacheHits     = 0;
        $cacheMisses   = 0;
        $processed     = 0;
        $batchMatched  = [];
        $batchUnmatched = [];
        $batchStart    = 1;

        foreach ($channels as $channel) {
            $displayName = trim((string) ($channel->title_custom ?? $channel->title ?? $channel->name_custom ?? $channel->name ?? ''));
            if ($displayName === '') {
                continue;
            }

            $processed++;
            $normalizedName = $this->normalizeChannelName($displayName, $normConfig);
            $cacheKey       = $rawCodes . ':' . mb_strtolower($normalizedName, 'UTF-8');

            if (! $ignoreCache && array_key_exists($cacheKey, $cache['matches'])) {
                $logoUrl   = $cache['matches'][$cacheKey] ?: null;
                $cacheHits++;
            } else {
                $logoUrl = null;
                foreach ($countryIndexes as $code => $data) {
                    $logoUrl = $this->resolveLogoUrl($normalizedName, $code, $data['folder'], $data['index'], $data['byBasename']);
                    if ($logoUrl !== null) {
                        break;
                    }
                }
                $cache['matches'][$cacheKey] = $logoUrl ?? '';
                $cacheChanged = true;
                $cacheMisses++;
            }

            if ($logoUrl !== null) {
                $matched++;
                $batchMatched[$displayName] = $logoUrl;
                if (! $isDryRun && ($channel->logo ?? '') !== $logoUrl) {
                    Channel::where('id', $channel->id)->update(['logo' => $logoUrl]);
                }
            } else {
                $unmatched++;
                $batchUnmatched[] = $displayName;
            }

            if ($processed % self::LOG_BATCH_SIZE === 0) {
                $context->info(
                    sprintf('Channels %d–%d: %d matched, %d unmatched.', $batchStart, $processed, count($batchMatched), count($batchUnmatched)),
                    ['matched' => $batchMatched, 'unmatched' => $batchUnmatched],
                );
                $batchMatched   = [];
                $batchUnmatched = [];
                $batchStart     = $processed + 1;
                $context->heartbeat(progress: (int) (($processed / $total) * 100));
            }
        }

        if ($batchMatched !== [] || $batchUnmatched !== []) {
            $context->info(
                sprintf('Channels %d–%d: %d matched, %d unmatched.', $batchStart, $processed, count($batchMatched), count($batchUnmatched)),
                ['matched' => $batchMatched, 'unmatched' => $batchUnmatched],
            );
        }

        if ($cacheChanged && ! $isDryRun) {
            $this->saveCache($cache);
        }

        return PluginActionResult::success(
            sprintf('%d of %d channel(s) matched%s.', $matched, $total, $isDryRun ? ' (dry run — no changes written)' : ''),
            [
                'matched'      => $matched,
                'unmatched'    => $unmatched,
                'total'        => $total,
                'cache_hits'   => $cacheHits,
                'cache_misses' => $cacheMisses,
                'country_code' => $countryCode,
                'dry_run'      => $isDryRun,
                'ignore_cache' => $ignoreCache,
            ]
        );
    }

    // =========================================================================
    // Manifest fetching & indexing
    // =========================================================================

    /**
     * Fetch logos-manifest.json from logos.austheim.app.
     *
     * The manifest has the shape:
     *   { "generated": "auto", "total": N, "logos": [
     *       { "name": "nrk1-no.png", "path": "/countries/nordic/norway/nrk1-no.png", "country": "nordic/norway" },
     *       ...
     *   ]}
     *
     * We cache the flat array of logo objects under the key "manifest:v1".
     * The cache is shared across all country lookups in a run.
     *
     * @param  array<string, mixed> $cache
     * @return list<array{name: string, path: string, country: string}>
     */
    private function fetchManifest(array &$cache, bool &$cacheChanged, bool $ignoreCache): array
    {
        $cacheKey = 'manifest:v1';

        if (! $ignoreCache && isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        try {
            $response = Http::timeout(20)->get($this->manifestUrl);
            if ($response->successful()) {
                $logos = $response->json('logos') ?? [];
                if (is_array($logos) && $logos !== []) {
                    $cache[$cacheKey] = $logos;
                    $cacheChanged     = true;
                    return $logos;
                }
            }
        } catch (Throwable) {}

        return [];
    }

    /**
     * Build two lookup structures for a specific country folder from the full manifest:
     *
     *  $index      — relative path (after /countries/<folder>/) → true
     *                e.g. "nrk1-no.png" => true, "hd/nrk1-hd-no.png" => true
     *
     *  $byBasename — lowercase filename → [relative paths…]
     *                e.g. "nrk1-no.png" => ["nrk1-no.png", "hd/nrk1-no.png"]
     *
     * The "path" field in the manifest is "/countries/nordic/norway/nrk1-no.png".
     * We strip the leading "/countries/<countryFolder>/" to get the relative path.
     *
     * @param  list<array{name: string, path: string, country: string}> $manifest
     * @return array{0: array<string, true>, 1: array<string, list<string>>}
     */
    private function buildCountryIndex(array $manifest, string $countryFolder): array
    {
        $prefix    = '/countries/' . $countryFolder . '/';
        $prefixLen = strlen($prefix);
        $index     = [];
        $byBasename = [];

        foreach ($manifest as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $path = (string) ($entry['path'] ?? '');

            // Only entries that belong to this country folder.
            if (! str_starts_with($path, $prefix)) {
                continue;
            }

            $relativePath = substr($path, $prefixLen);          // e.g. "nrk1-no.png" or "hd/nrk1-hd-no.png"
            $lowRelative  = strtolower($relativePath);
            $lowBasename  = strtolower(basename($relativePath));

            $index[$lowRelative]     = true;
            $byBasename[$lowBasename][] = $lowRelative;
        }

        return [$index, $byBasename];
    }

    // =========================================================================
    // Logo resolution
    // =========================================================================

    /**
     * Attempt to resolve a CDN logo URL for the given channel name.
     *
     * When an index is available (normal path), performs a comprehensive
     * filename-based search across all subfolders, preferring HD for HD-hinted
     * channels.  Falls back to sequential CDN HEAD checks only when no manifest
     * data is available for the country.
     *
     * @param array<string, true>        $index
     * @param array<string, list<string>> $byBasename
     */
    private function resolveLogoUrl(
        string $channelName,
        string $countryCode,
        string $countryFolder,
        array  $index,
        array  $byBasename
    ): ?string {
        $slugs = array_values(array_unique(array_filter([
            $this->slugify($channelName, false),
            $this->slugify($channelName, true),
        ])));

        if ($slugs === []) {
            return null;
        }

        $filenames = $this->buildFilenamesForSlugs($slugs, $countryCode);

        // ── Index path (fast, O(1) per filename) ──────────────────────────
        if ($index !== []) {
            $result = $this->resolveFromIndex($filenames, $channelName, $countryFolder, $byBasename);
            if ($result !== null) {
                return $result;
            }
            return $this->compactIndexMatch($slugs, $countryCode, $countryFolder, $channelName, $index);
        }

        // ── HEAD fallback (slow, one HTTP call per candidate) ──────────────
        foreach ($this->preferredQualityFolders($channelName) as $folder) {
            foreach ($filenames as $filename) {
                $relativePath = $folder === '' ? $filename : "{$folder}/{$filename}";
                $url          = "{$this->cdnBase}/{$countryFolder}/{$relativePath}";
                if ($this->urlExists($url)) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * Resolve by searching the pre-built basename lookup across all subfolders.
     * Prefers HD subfolders for HD-hinted channel names.
     *
     * @param array<int, string>          $filenames
     * @param array<string, list<string>> $byBasename
     */
    private function resolveFromIndex(
        array  $filenames,
        string $channelName,
        string $countryFolder,
        array  $byBasename
    ): ?string {
        $hdPreferred = (bool) preg_match('/\b(hd|fhd|uhd|4k|8k|1080[pi]|720p)\b/iu', $channelName);

        foreach ($filenames as $filename) {
            $lowFilename = strtolower($filename);

            if (! isset($byBasename[$lowFilename])) {
                continue;
            }

            $paths = $byBasename[$lowFilename];

            if (count($paths) === 1) {
                return "{$this->cdnBase}/{$countryFolder}/{$paths[0]}";
            }

            $hdMatch   = null;
            $rootMatch = null;

            foreach ($paths as $path) {
                $inHd = str_contains($path, '/hd/') || str_starts_with($path, 'hd/');
                if ($inHd) {
                    $hdMatch ??= $path;
                } elseif (! str_contains($path, '/')) {
                    $rootMatch ??= $path;
                }
            }

            if ($hdPreferred && $hdMatch !== null) {
                return "{$this->cdnBase}/{$countryFolder}/{$hdMatch}";
            }

            if ($rootMatch !== null) {
                return "{$this->cdnBase}/{$countryFolder}/{$rootMatch}";
            }

            return "{$this->cdnBase}/{$countryFolder}/{$paths[0]}";
        }

        return null;
    }

    /**
     * Compact matching fallback — strips all hyphens for fuzzy matching.
     * Handles cases like "sport1" matching "sport-1-de.png".
     *
     * @param array<int, string>  $slugs
     * @param array<string, true> $index
     */
    private function compactIndexMatch(
        array  $slugs,
        string $countryCode,
        string $countryFolder,
        string $channelName,
        array  $index
    ): ?string {
        $suffixes             = ["-{$countryCode}.png", '.png'];
        $qualityFolders       = $this->preferredQualityFolders($channelName);
        $compactChannelSlugs  = array_map(fn (string $s): string => str_replace('-', '', $s), $slugs);

        foreach ($qualityFolders as $preferredFolder) {
            foreach ($index as $relativePath => $_) {
                $basename  = basename($relativePath);
                $suffixLen = 0;

                foreach ($suffixes as $suffix) {
                    if (str_ends_with($basename, $suffix)) {
                        $suffixLen = strlen($suffix);
                        break;
                    }
                }

                if ($suffixLen === 0) {
                    continue;
                }

                $folder   = dirname($relativePath);
                $folder   = $folder === '.' ? '' : $folder;
                $isHdPath = $folder === 'hd' || str_ends_with($folder, '/hd');
                $wantsHd  = $preferredFolder === 'hd';

                if ($wantsHd !== $isHdPath) {
                    continue;
                }

                $indexSlug = str_replace('-', '', substr($basename, 0, -$suffixLen));

                foreach ($compactChannelSlugs as $compact) {
                    if ($indexSlug === $compact) {
                        return "{$this->cdnBase}/{$countryFolder}/{$relativePath}";
                    }
                }
            }
        }

        return null;
    }

    // =========================================================================
    // Slug / filename helpers
    // =========================================================================

    /**
     * Build the ordered list of candidate filenames for the given slugs.
     *
     * @param  array<int, string> $slugs
     * @return array<int, string>
     */
    private function buildFilenamesForSlugs(array $slugs, string $countryCode): array
    {
        $filenames = [];

        foreach ($slugs as $slug) {
            $filenames[] = "{$slug}-{$countryCode}.png";
            $filenames[] = "{$slug}.png";

            $parts        = explode('-', $slug);
            $lastPart     = end($parts);
            $qualitySuffs = ['hd', 'fhd', 'uhd', 'sd', '4k', '8k'];

            if (count($parts) > 1 && ! ctype_digit($lastPart) && ! in_array($lastPart, $qualitySuffs, true)) {
                $shortened = implode('-', array_slice($parts, 0, -1));
                if ($shortened !== '') {
                    $filenames[] = "{$shortened}-{$countryCode}.png";
                }
            }
        }

        return array_values(array_unique($filenames));
    }

    /** @return array<int, string> */
    private function preferredQualityFolders(string $channelName): array
    {
        $hasHdHint = (bool) preg_match('/\b(hd|fhd|uhd|4k|8k|1080[pi]|720p)\b/iu', $channelName);
        return $hasHdHint ? ['hd', ''] : ['', 'hd'];
    }

    /**
     * Number words ↔ digits for dual-variant slug generation.
     * e.g. "BBC 1" can match "bbc-one-gb.png" and vice-versa.
     *
     * @var array<string, string>
     */
    private const NUMBER_WORDS = [
        'one'   => '1',  'two'   => '2',  'three' => '3',
        'four'  => '4',  'five'  => '5',  'six'   => '6',
        'seven' => '7',  'eight' => '8',  'nine'  => '9',
        'ten'   => '10', 'eleven'=> '11', 'twelve'=> '12',
    ];

    /**
     * Country prefix patterns that IPTV providers prepend to channel names.
     * e.g. "UK: BBC 1", "NO: NRK1", "US: ESPN" — the prefix is stripped
     * before slugifying so it doesn't end up in the candidate filenames.
     */
    private const COUNTRY_PREFIX_PATTERN = '/^[A-Z]{2,3}:\s*/u';

    /**
     * Normalise a channel name into a hyphenated slug.
     *
     * Always strips leading country prefixes (e.g. "UK: ", "NO: ").
     * Returns the base slug; callers should also generate a number-swapped
     * variant via slugifyWithNumberSwap() for broader matching.
     */
    private function slugify(string $name, bool $stripQualityTags = true): string
    {
        // Strip leading country prefix e.g. "UK: ", "NO: ", "US: "
        $name = preg_replace(self::COUNTRY_PREFIX_PATTERN, '', $name) ?? $name;

        // Split camelCase / PascalCase before lowercasing
        $name = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $name) ?? $name;
        $name = mb_strtolower($name, 'UTF-8');

        if ($stripQualityTags) {
            $name = preg_replace('/\b(hd|fhd|uhd|4k|8k|sd|1080[pi]|720p|hevc|h\.?264|h\.?265)\s*(raw|low|high)?\b/iu', '', $name) ?? $name;
        }

        // Strip transport/source terms (protect "SAT.1" → sat followed by dot+digit)
        $name = preg_replace('/\b(cable|sat(?:ellite)?(?![.\s]*\d)|terrestrial|dvb[tcsh]?|iptv|ott|fta|stream|linear)\b/iu', '', $name) ?? $name;

        // Strip bracket contents
        $name = preg_replace('/[\(\[\{][^\)\]\}]*[\)\]\}]/', '', $name) ?? $name;

        $name = str_replace('&', ' and ', $name);
        $name = str_replace('.', ' ', $name);
        $name = str_replace('+', ' plus ', $name);

        // Keep only unicode letters, digits, and spaces
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? $name;

        $name = str_replace(' ', '-', $name);
        $name = preg_replace('/-+/', '-', $name) ?? $name;

        return trim($name, '-');
    }

    /**
     * Produce a number-swapped variant of a slug.
     *
     * If the slug contains digit words (one, two …), replace them with digits.
     * If the slug contains digits, replace them with words.
     * Returns null when no swap is possible (nothing to swap).
     *
     * Examples:
     *   "bbc-1"   → "bbc-one"
     *   "bbc-one" → "bbc-1"
     *   "itv-2"   → "itv-two"
     */
    private function swapNumbers(string $slug): ?string
    {
        // Try digits → words first
        $swapped = preg_replace_callback(
            '/(?<=-|^)(\d+)(?=-|$)/',
            function (array $m): string {
                $flipped = array_flip(self::NUMBER_WORDS);
                return $flipped[$m[1]] ?? $m[1];
            },
            $slug
        ) ?? $slug;

        if ($swapped !== $slug) {
            return $swapped;
        }

        // Try words → digits
        $pattern = '/(?<=-|^)(' . implode('|', array_keys(self::NUMBER_WORDS)) . ')(?=-|$)/i';
        $swapped = preg_replace_callback(
            $pattern,
            fn (array $m): string => self::NUMBER_WORDS[strtolower($m[1])] ?? $m[1],
            $slug
        ) ?? $slug;

        return $swapped !== $slug ? $swapped : null;
    }

    private function urlExists(string $url): bool
    {
        try {
            return Http::timeout(8)->head($url)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    // =========================================================================
    // Cache
    // =========================================================================

    /**
     * @return array{version: int, cached_at: string, matches: array<string, string>}
     */
    private function loadCache(int $cacheTtlDays): array
    {
        $empty = ['version' => self::CACHE_VERSION, 'cached_at' => now()->toIso8601String(), 'matches' => []];

        try {
            if (! Storage::disk('local')->exists(self::CACHE_FILE)) {
                return $empty;
            }

            $data = json_decode((string) Storage::disk('local')->get(self::CACHE_FILE), true);

            if (! is_array($data) || ! isset($data['matches']) || ($data['version'] ?? 0) < self::CACHE_VERSION) {
                return $empty;
            }

            if ($cacheTtlDays > 0 && isset($data['cached_at'])) {
                if (Carbon::parse($data['cached_at'])->diffInDays(now()) >= $cacheTtlDays) {
                    return $empty;
                }
            }

            return $data;
        } catch (Throwable) {
            return $empty;
        }
    }

    private function saveCache(array $cache): void
    {
        try {
            Storage::disk('local')->put(
                self::CACHE_FILE,
                json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (Throwable) {
            // Non-fatal — next run will re-check the CDN.
        }
    }

    // =========================================================================
    // Channel name normalisation
    // =========================================================================

    /**
     * @param  array<string, mixed> $settings
     * @return array{enabled: bool, strip_unicode: bool, strip_raw: bool, strip_provider_info: bool, provider_terms: list<string>, strip_quality_extras: bool, custom_patterns: list<string>}
     */
    private function buildNormalizationConfig(array $settings): array
    {
        $enabled = (bool) ($settings['normalize_channel_names'] ?? false);

        $providerTerms = [];
        foreach (explode("\n", trim((string) ($settings['normalize_provider_terms'] ?? ''))) as $line) {
            if (($line = trim($line)) !== '') {
                $providerTerms[] = $line;
            }
        }

        $customPatterns = [];
        foreach (explode("\n", trim((string) ($settings['normalize_custom_patterns'] ?? ''))) as $line) {
            if (($line = trim($line)) !== '' && @preg_match($line, '') !== false) {
                $customPatterns[] = $line;
            }
        }

        return [
            'enabled'              => $enabled,
            'strip_unicode'        => $enabled && (bool) ($settings['normalize_strip_unicode']        ?? true),
            'strip_raw'            => $enabled && (bool) ($settings['normalize_strip_raw']            ?? true),
            'strip_provider_info'  => $enabled && (bool) ($settings['normalize_strip_provider_info']  ?? true),
            'provider_terms'       => $providerTerms,
            'strip_quality_extras' => $enabled && (bool) ($settings['normalize_strip_quality_extras'] ?? true),
            'custom_patterns'      => $customPatterns,
        ];
    }

    private function normalizeChannelName(string $name, array $config): string
    {
        if (! $config['enabled']) {
            return $name;
        }

        if ($config['strip_unicode']) {
            $unicodeMap = [
                '⁰' => '0', '¹' => '1', '²' => '2', '³' => '3', '⁴' => '4',
                '⁵' => '5', '⁶' => '6', '⁷' => '7', '⁸' => '8', '⁹' => '9',
                '⁺' => '+', '⁻' => '-',
                '₀' => '0', '₁' => '1', '₂' => '2', '₃' => '3', '₄' => '4',
                '₅' => '5', '₆' => '6', '₇' => '7', '₈' => '8', '₉' => '9',
                'ᴀ' => 'A', 'ʙ' => 'B', 'ᴄ' => 'C', 'ᴅ' => 'D', 'ᴇ' => 'E',
                'ꜰ' => 'F', 'ɢ' => 'G', 'ʜ' => 'H', 'ɪ' => 'I', 'ᴊ' => 'J',
                'ᴋ' => 'K', 'ʟ' => 'L', 'ᴍ' => 'M', 'ɴ' => 'N', 'ᴏ' => 'O',
                'ᴘ' => 'P', 'ꞯ' => 'Q', 'ʀ' => 'R', 'ꜱ' => 'S', 'ᴛ' => 'T',
                'ᴜ' => 'U', 'ᴠ' => 'V', 'ᴡ' => 'W', 'ʏ' => 'Y', 'ᴢ' => 'Z',
            ];
            $name = strtr($name, $unicodeMap);
        }

        if ($config['strip_raw']) {
            $name = (string) preg_replace('/\b(HD|FHD|UHD|SD)\s*raw\b/iu', '$1', $name);
        }

        if ($config['strip_provider_info']) {
            $name = (string) preg_replace('/\b(Cable|Sat(?:ellite)?(?![.\s]*\d)|Terrestrial|DVB[TCSH]?|IPTV|OTT|FTA|Stream|Linear)\b/iu', '', $name);
        }

        if ($config['strip_provider_info'] && $config['provider_terms'] !== []) {
            $escaped = array_map(fn (string $t): string => preg_quote($t, '/'), $config['provider_terms']);
            $name    = (string) preg_replace('/\b(' . implode('|', $escaped) . ')\b/iu', '', $name);
        }

        if ($config['strip_quality_extras']) {
            $name = (string) preg_replace('/\b(HD|FHD|UHD|SD)\s*(Low|High)\b/iu', '$1', $name);
        }

        foreach ($config['custom_patterns'] as $pattern) {
            $result = @preg_replace($pattern, '', $name);
            if ($result !== null) {
                $name = $result;
            }
        }

        return trim((string) preg_replace('/\s{2,}/', ' ', $name));
    }
}
