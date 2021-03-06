<?php

namespace App\Jobs;

use App\Events\ScanUpdated;
use App\JavascriptFileParser;
use App\Report\RatingAgent;
use App\Scan;
use App\ScannerClient;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Log;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class WebsiteScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $scan;
    protected $report;
    protected $responses;

    public function __construct(Scan $scan)
    {
        $this->scan = $scan;
    }

    public function handle()
    {
        $this->report = [];
        $this->responses = [];

        $baseAddress = $this->scan->website->normalized_url;

        $this->report['base_address'] = $baseAddress;
        $this->report['requests'] = [];

        $workingUrls = [];

        $this->report['urls'] = [];

        $prefixes = [''];

        // Add protocol just so it can be parsed
        $parsedUrl = parse_url("https://$baseAddress");

        // Do not try to access www. if the host is an ip
        if (!filter_var(array_get($parsedUrl, 'host'), FILTER_VALIDATE_IP)) {
            $prefixes[] = 'www.';
        }

        // Try the various urls that might be used to access the forum
        foreach ($prefixes as $prefix) {
            foreach (['https', 'http'] as $scheme) {
                $url = "$scheme://$prefix$baseAddress";

                $urlReport = [];

                try {
                    $response = $this->doRequest($url);

                    $urlReport['status'] = $response->getStatusCode();
                    $urlReport['headers'] = array_only($response->getHeaders(), [
                        'Content-Security-Policy',
                        'Content-Security-Policy-Report-Only',
                        'Strict-Transport-Security',
                    ]);

                    switch ($response->getStatusCode()) {
                        case 301:
                        case 302:
                            $urlReport['type'] = 'redirect';
                            $urlReport['redirect_to'] = array_first($response->getHeader('Location'));

                            break;
                        case 200:
                            $urlReport['type'] = 'ok';
                            $workingUrls[] = $url;

                            break;
                        default:
                            $urlReport['type'] = 'httperror';
                    }
                } catch (Exception $exception) {
                    $urlReport['type'] = 'error';
                    $urlReport['exception_class'] = get_class($exception);
                    $urlReport['exception_message'] = $exception->getMessage();
                }

                $this->report['urls'][($prefix === 'www.' ? 'www' : 'apex') . '-' . $scheme] = $urlReport;
            }
        }

        $this->report['multiple_urls'] = count($workingUrls) > 1;

        $canonicalUrl = array_first($workingUrls);
        $this->report['canonical_url'] = $canonicalUrl;

        $homepage = null;

        if ($canonicalUrl) {
            try {
                $homepage = new Crawler($this->doRequest($canonicalUrl)->getBody()->getContents());
            } catch (Exception $exception) {
                $this->report['homepage'] = [
                    'failed' => true,
                    'exception_class' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                ];
            }
        }

        if ($homepage) {
            $homepageReport = [];

            $flarumUrl = null;

            $homepage->filter('head link[rel="stylesheet"]')->each(function (Crawler $link) use (&$flarumUrl) {
                $href = $link->attr('href');

                if (!$flarumUrl && str_contains($href, '/assets/forum-')) {
                    $flarumUrl = array_first(explode('/assets/forum-', $href, 2));
                }
            });

            $homepageReport['flarum_url'] = $flarumUrl;

            $modules = null;
            $boot = null;

            $homepage->filter('body script')->each(function (Crawler $script) use (&$modules, &$boot) {
                $content = $script->text();

                if (!str_contains($content, 'app.boot')) {
                    return;
                }

                $matches = [];
                if (preg_match('~var modules = (\[[^\n]+\])~', $content, $matches) === 1) {
                    $readModules = json_decode($matches[1]);

                    $modules = [];

                    if (is_array($readModules)) {
                        foreach ($readModules as $module) {
                            if (is_string($module)) {
                                $modules[] = $module;
                            }
                        }
                    }
                }

                $matches = [];
                if (preg_match('~app\.boot\(([^\n]+)\)~', $content, $matches) === 1) {
                    $boot = json_decode($matches[1], true);

                    if (is_array($boot)) {
                        foreach (array_get($boot, 'resources', []) as $resource) {
                            if (array_get($resource, 'type') === 'forums') {
                                $boot = [
                                    'base_url' => array_get($resource, 'attributes.baseUrl'),
                                    'base_path' => array_get($resource, 'attributes.basePath'),
                                    'debug' => array_get($resource, 'attributes.debug'),
                                    'title' => array_get($resource, 'attributes.title'),
                                ];

                                break;
                            }
                        }
                    }
                }
            });

            $homepageReport['modules'] = $modules;
            $homepageReport['boot'] = $boot;

            $maliciousAccess = [];

            $javascriptModules = [
                'forum' => null,
                'admin' => null,
            ];

            if ($flarumUrl) {
                // We check for $flarumUrl to know whether it's a Flarum install
                // But we then use $canonicalUrl so we always have the proper protocol and can't be fooled into hitting another host
                $safeFlarumUrl = rtrim($canonicalUrl, '/');

                $tryMaliciousAccess = [
                    'vendor' => [
                        'vendor/composer/installed.json',
                        'vendor/flarum/core/LICENSE',
                    ],
                    'storage' => [
                        'storage/logs/flarum.log',
                        'storage/views/7dc8e518535b1d01db47bee524631424', // app.blade.php in beta7
                    ],
                    'composer' => [
                        'composer.json',
                        'composer.lock',
                    ],
                ];

                foreach ($tryMaliciousAccess as $access => $urls) {
                    $accessReport = [
                        'access' => false,
                        'urls' => [],
                        'errors' => [],
                    ];

                    foreach ($urls as $url) {
                        try {
                            $fullUrl = "$safeFlarumUrl/$url";
                            $response = $this->doRequest($fullUrl, true);

                            if ($response->getStatusCode() === 200) {
                                $accessReport['access'] = true;
                                $accessReport['urls'][] = $fullUrl;
                            }
                        } catch (Exception $exception) {
                            // Errors are not considered to allow malicious access
                            // But the messages are still saved just in case
                            $accessReport['errors'][] = [
                                'url' => $fullUrl,
                                'exception_class' => get_class($exception),
                                'exception_message' => $exception->getMessage(),
                            ];
                        }
                    }

                    $maliciousAccess[$access] = $accessReport;
                }

                $forumJsHash = null;
                $adminJsHash = null;

                $homepage->filter('body script[src]')->each(function (Crawler $link) use (&$forumJsHash) {
                    $src = $link->attr('src');

                    if (!$forumJsHash && preg_match('~assets/forum\-([0-9a-f]{8})\.js$~', $src, $matches) === 1) {
                        $forumJsHash = $matches[1];
                    }
                });

                try {
                    $revManifest = \GuzzleHttp\json_decode($this->doRequest("$safeFlarumUrl/assets/rev-manifest.json")->getBody()->getContents(), true);

                    $manifestForumJsHash = array_get($revManifest, 'forum.js');
                    $manifestAdminJsHash = array_get($revManifest, 'admin.js');

                    if (preg_match('~^[0-9a-f]{8}$~', $manifestForumJsHash) === 1) {
                        if ($forumJsHash && $forumJsHash !== $manifestForumJsHash) {
                            Log::info('Scan ' . $this->scan->uid . ' forum.js hash from homepage (' . $forumJsHash . ') is different from rev-manifest (' . $manifestForumJsHash . ')');
                        }

                        $forumJsHash = $manifestForumJsHash;
                    }

                    if (preg_match('~^[0-9a-f]{8}$~', $manifestAdminJsHash) === 1) {
                        $adminJsHash = $manifestAdminJsHash;
                    }
                } catch (Exception $exception) {
                    // silence errors
                }

                foreach ([
                             'forum' => $forumJsHash,
                             'admin' => $adminJsHash,
                         ] as $stack => $hash) {
                    try {
                        if (!$hash) {
                            continue;
                        }

                        $content = $this->doRequest("$safeFlarumUrl/assets/$stack-$hash.js")->getBody()->getContents();
                        $javascriptParser = new JavascriptFileParser($content);

                        $javascriptModules[$stack] = [];

                        foreach ($javascriptParser->modules() as $module) {
                            $javascriptModules[$stack][array_get($module, 'module')] = md5(array_get($module, 'code'));
                        }
                    } catch (Exception $exception) {
                        // silence errors
                    }
                }
            }

            $this->report['homepage'] = $homepageReport;
            $this->report['malicious_access'] = $maliciousAccess;
            $this->report['javascript_modules'] = $javascriptModules;
        }

        $this->scan->report = $this->report;
        $this->scan->scanned_at = Carbon::now();

        try {
            $ratingAgent = new RatingAgent($this->scan);
            $ratingAgent->rate();

            $this->scan->rating = $ratingAgent->rating;
        } catch (Exception $exception) {
            // ignore errors
        }

        $this->scan->save();

        if ($canonicalUrl && $this->scan->website->canonical_url !== $canonicalUrl) {
            $this->scan->website->canonical_url = $canonicalUrl;
        }

        $title = array_get($this->scan->report, 'homepage.boot.title');

        if ($title && $this->scan->website->name !== $title) {
            $this->scan->website->name = $title;
        }

        if ($this->scan->rating && $this->scan->website->last_rating !== $this->scan->rating) {
            $this->scan->website->last_rating = $this->scan->rating;
        }

        if (!$this->scan->hidden) {
            $this->scan->website->last_public_scanned_at = $this->scan->scanned_at;
        }

        if ($this->scan->website->isDirty()) {
            $this->scan->website->save();
        }

        event(new ScanUpdated($this->scan));
    }

    protected function doRequest(string $url, bool $head = false): ResponseInterface
    {
        if (!array_has($this->responses, $url)) {
            /**
             * @var $client ScannerClient
             */
            $client = app(ScannerClient::class);

            $requestDate = Carbon::now()->toIso8601String();
            $requestTime = microtime(true);

            try {
                $response = $client->request($head ? 'head' : 'get', $url);
                $this->responses[$url] = $response;

                $content = $response->getBody()->getContents();

                $bodySize = strlen($content);
                $maxSize = config('scanner.keep_max_response_body_size');

                if ($bodySize > $maxSize) {
                    $content = substr($content, 0, $maxSize) . "\n\n(response truncated. Original length $bodySize)";
                }

                $this->report['requests'][] = [
                    'request' => [
                        'date' => $requestDate,
                        'url' => $url,
                        'method' => $head ? 'HEAD' : 'GET',
                        'headers' => $client->getConfig('headers'),
                    ],
                    'response' => [
                        'time' => round((microtime(true) - $requestTime) * 1000),
                        'status_code' => $response->getStatusCode(),
                        'reason_phrase' => $response->getReasonPhrase(),
                        'protocol_version' => $response->getProtocolVersion(),
                        'headers' => $response->getHeaders(),
                        'body' => $content,
                    ],
                ];

                $response->getBody()->rewind();
            } catch (Exception $exception) {
                $this->report['requests'][] = [
                    'request' => [
                        'date' => $requestDate,
                        'url' => $url,
                        'method' => 'GET',
                        'headers' => $client->getConfig('headers'),
                    ],
                    'exception' => [
                        'time' => round((microtime(true) - $requestTime) * 1000),
                        'class' => get_class($exception),
                        'message' => $exception->getMessage(),
                    ],
                ];

                // We also cache exceptions
                $this->responses[$url] = $exception;
            }
        }

        $response = array_get($this->responses, $url);

        if ($response instanceof Exception) {
            throw $response;
        }

        return $response;
    }

    public function failed(Exception $exception)
    {
        $this->scan->report = [
            'failed' => true,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
        ];
        $this->scan->scanned_at = Carbon::now();
        $this->scan->save();

        event(new ScanUpdated($this->scan));
    }
}
