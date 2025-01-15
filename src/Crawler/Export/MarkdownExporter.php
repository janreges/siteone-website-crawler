<?php

/*
 * This file is part of the SiteOne Crawler.
 *
 * (c) Ján Regeš <jan.reges@siteone.cz>
 */

declare(strict_types=1);

namespace Crawler\Export;

use Crawler\Crawler;
use Crawler\Export\Utils\OfflineUrlConverter;
use Crawler\Export\Utils\TargetDomainRelation;
use Crawler\FoundUrl;
use Crawler\Options\Group;
use Crawler\Options\Option;
use Crawler\Options\Options;
use Crawler\Options\Type;
use Crawler\ParsedUrl;
use Crawler\Result\VisitedUrl;
use Crawler\Utils;
use Exception;

class MarkdownExporter extends BaseExporter implements Exporter
{

    const GROUP_MARKDOWN_EXPORTER = 'markdown-exporter';

    private static $contentTypesThatRequireChanges = [
        Crawler::CONTENT_TYPE_ID_HTML,
        Crawler::CONTENT_TYPE_ID_REDIRECT
    ];

    /**
     * Directory where markdown files will be stored. If not set, markdown export is disabled.
     * @var string|null
     */
    protected ?string $markdownExportDirectory = null;

    /**
     * Do not export and show images in markdown files. Images are enabled by default.
     * @var bool
     */
    protected bool $markdownDisableImages = false;

    /**
     * Do not export and link files other than HTML/CSS/JS/fonts/images - eg. PDF, ZIP, etc.
     * @var bool
     */
    protected bool $markdownDisableFiles = false;

    /**
     * Exclude some page content (DOM elements) from markdown export defined by CSS selectors like 'header', 'footer', '.header', '#footer', etc.
     * @var string[]
     */
    protected array $markdownExcludeSelector = [];

    /**
     * For debug - when filled it will activate debug mode and store only URLs which match one of these regexes
     * @var string[]
     */
    protected array $markdownExportStoreOnlyUrlRegex = [];

    /**
     * Ignore issues with storing files and continue saving other files. Useful in case of too long file names (depends on OS, FS, base directory, etc.)
     * @var bool
     */
    protected bool $markdownIgnoreStoreFileError = false;

    /**
     * Replace HTML/JS/CSS content with `xxx -> bbb` or regexp in PREG format: `/card[0-9]/ -> card`
     *
     * @var string[]
     */
    protected array $markdownReplaceContent = [];

    /**
     * Instead of using a short hash instead of a query string in the filename, just replace some characters.
     * You can use a regular expression. E.g. '/([^&]+)=([^&]*)(&|$)/' -> '$1-$2_'
     *
     * @var string[]
     */
    protected array $markdownReplaceQueryString = [];

    /**
     * Exporter is activated when --markdown-export-dir is set
     * @return bool
     */
    public function shouldBeActivated(): bool
    {
        ini_set('pcre.backtrack_limit', '100000000');
        ini_set('pcre.recursion_limit', '100000000');
        $this->markdownExportDirectory = $this->markdownExportDirectory ? rtrim($this->markdownExportDirectory, '/') : null;
        return $this->markdownExportDirectory !== null;
    }

    /**
     * Export all visited URLs to directory with markdown version of the website
     * @return void
     * @throws Exception
     */
    public function export(): void
    {
        $startTime = microtime(true);
        $visitedUrls = $this->status->getVisitedUrls();

        // user-defined markdownReplaceQueryString will deactivate replacing query string with hash and use custom replacement
        OfflineUrlConverter::setReplaceQueryString($this->markdownReplaceQueryString);

        // store only URLs with relevant content types
        $validContentTypes = [Crawler::CONTENT_TYPE_ID_HTML, Crawler::CONTENT_TYPE_ID_REDIRECT];
        if (!$this->markdownDisableImages) {
            $validContentTypes[] = Crawler::CONTENT_TYPE_ID_IMAGE;
        }
        if (!$this->markdownDisableFiles) {
            $validContentTypes[] = Crawler::CONTENT_TYPE_ID_DOCUMENT;
        }

        // filter only relevant URLs with OK status codes
        $exportedUrls = array_filter($visitedUrls, function (VisitedUrl $visitedUrl) use ($validContentTypes) {
            // do not store images if they are not from <img src="..."> (e.g. background-image in CSS or alternative image sources in <picture>)
            if ($visitedUrl->isImage() && !in_array($visitedUrl->sourceAttr, [FoundUrl::SOURCE_IMG_SRC, FoundUrl::SOURCE_A_HREF])) {
                return false;
            }

            return $visitedUrl->statusCode === 200 && in_array($visitedUrl->contentType, $validContentTypes);
        });
        /** @var VisitedUrl[] $exportedUrls */

        // store all allowed URLs
        try {
            foreach ($exportedUrls as $exportedUrl) {
                if ($this->isValidUrl($exportedUrl->url) && $this->shouldBeUrlStored($exportedUrl)) {
                    $this->storeFile($exportedUrl);
                }
            }
        } catch (Exception $e) {
            throw new Exception(__METHOD__ . ': ' . $e->getMessage());
        }

        // add info to summary
        $this->status->addInfoToSummary(
            'markdown-generated',
            sprintf(
                "Markdown content generated to '%s' and took %s",
                Utils::getOutputFormattedPath($this->markdownExportDirectory),
                Utils::getFormattedDuration(microtime(true) - $startTime)
            )
        );
    }

    /**
     * Store file of visited URL to offline export directory and apply all required changes
     *
     * @param VisitedUrl $visitedUrl
     * @return void
     * @throws Exception
     */
    private function storeFile(VisitedUrl $visitedUrl): void
    {
        $content = $this->status->getUrlBody($visitedUrl->uqId);

        // apply required changes through all content processors
        if (in_array($visitedUrl->contentType, self::$contentTypesThatRequireChanges)) {
            $this->crawler->getContentProcessorManager()->applyContentChangesForOfflineVersion(
                $content,
                $visitedUrl->contentType,
                ParsedUrl::parse($visitedUrl->url),
                true
            );

            // apply custom content replacements
            if ($content && $this->markdownReplaceContent) {
                foreach ($this->markdownReplaceContent as $replace) {
                    $parts = explode('->', $replace);
                    $replaceFrom = trim($parts[0]);
                    $replaceTo = trim($parts[1] ?? '');
                    $isRegex = preg_match('/^([\/#~%]).*\1[a-z]*$/i', $replaceFrom);
                    if ($isRegex) {
                        $content = preg_replace($replaceFrom, $replaceTo, $content);
                    } else {
                        $content = str_replace($replaceFrom, $replaceTo, $content);
                    }
                }
            }
        }

        // sanitize and replace special chars because they are not allowed in file/dir names on some platforms (e.g. Windows)
        // same logic is in method convertUrlToRelative()
        $storeFilePath = sprintf('%s/%s',
            $this->markdownExportDirectory,
            OfflineUrlConverter::sanitizeFilePath($this->getRelativeFilePathForFileByUrl($visitedUrl), false)
        );

        $directoryPath = dirname($storeFilePath);
        if (!is_dir($directoryPath)) {
            if (!mkdir($directoryPath, 0777, true)) {
                throw new Exception("Cannot create directory '$directoryPath'");
            }
        }

        $saveFile = true;
        clearstatcache(true);

        // do not overwrite existing file if initial request was HTTPS and this request is HTTP, otherwise referenced
        // http://your.domain.tld/ will override wanted HTTPS page with small HTML file with meta redirect
        if (is_file($storeFilePath)) {
            if (!$visitedUrl->isHttps() && $this->crawler->getInitialParsedUrl()->isHttps()) {
                $saveFile = false;
                $message = "File '$storeFilePath' already exists and will not be overwritten because initial request was HTTPS and this request is HTTP: " . $visitedUrl->url;
                $this->output->addNotice($message);
                $this->status->addNoticeToSummary('markdown-exporter-store-file-ignored', $message);
                return;
            }
        }

        // replace HTML tables with Markdown tables (html-to-markdown do not support HTML tables yet)
        $content = $this->replaceHtmlTableToMdTable($content);

        if ($saveFile && @file_put_contents($storeFilePath, $content) === false) {
            // throw exception if file has extension (handle edge-cases as <img src="/icon/hash/"> and response is SVG)
            $exceptionOnError = preg_match('/\.[a-z0-9\-]{1,15}$/i', $storeFilePath) === 1;
            // AND if the exception should NOT be ignored
            if ($exceptionOnError && !$this->markdownIgnoreStoreFileError) {
                throw new Exception("Cannot store file '$storeFilePath'.");
            } else {
                $message = "Cannot store file '$storeFilePath' (undefined extension). Original URL: {$visitedUrl->url}";
                $this->output->addNotice($message);
                $this->status->addNoticeToSummary('markdown-exporter-store-file-error', $message);
                return;
            }
        }

        // convert *.html to *.md and remove *.html file
        if (str_ends_with($storeFilePath, '.html')) {
            $storeMdFilePath = substr($storeFilePath, 0, -5) . '.md';

            $args = '';
            if ($this->markdownExcludeSelector) {
                foreach ($this->markdownExcludeSelector as $selector) {
                    $args .= ' --exclude-selector ' . escapeshellarg($selector);
                }
            }

            $convertCommand = sprintf(
                'cat %s | %s/html2markdown ' . $args . ' > %s',
                escapeshellarg($storeFilePath),
                escapeshellarg(BASE_DIR . '/bin'),
                escapeshellarg($storeMdFilePath)
            );
            @shell_exec($convertCommand);
            @unlink($storeFilePath);

            if (!is_file($storeMdFilePath)) {
                $message = "Cannot convert HTML file to Markdown file '$storeMdFilePath'. Original URL: {$visitedUrl->url}";
                $this->output->addNotice($message);
                $this->status->addNoticeToSummary('markdown-exporter-store-file-error', $message);
                return;
            }

            $this->normalizeMarkdownFile($storeMdFilePath);
        }
    }

    /**
     * Normalize markdown file after conversion from HTML:
     *  - replace *.html links to *.md in saved *.md file
     *  - remove images if disabled
     *  - remove files if disabled
     *
     * @param string $mdFilePath
     * @return void
     */
    private function normalizeMarkdownFile(string $mdFilePath): void
    {
        $ignoreRegexes = $this->crawler->getCoreOptions()->ignoreRegex;
        $mdContent = file_get_contents($mdFilePath);

        // replace .html with .md in links, but respect ignore patterns
        $mdContent = preg_replace_callback(
            '/\[([^\]]*)\]\(([^)]+)\)/',
            function ($matches) use ($ignoreRegexes) {
                $linkText = $matches[1];
                $url = $matches[2];

                // check if URL matches any ignore pattern
                if ($ignoreRegexes) {
                    foreach ($ignoreRegexes as $ignoreRegex) {
                        if (preg_match($ignoreRegex, $url)) {
                            return $matches[0]; // Return link unchanged
                        }
                    }
                }

                // no ignore pattern matched - replace .html with .md
                $url = preg_replace(['/\.html/', '/\.html#/'], ['.md', '.md#'], $url);
                return "[$linkText]($url)";
            },
            $mdContent
        );

        if ($this->markdownDisableImages) {
            // replace image in anchor text, like in [![logo by @foobar](data:image/gif;base64,fooo= "logo by @foobar")](index.html)
            $mdContent = preg_replace('/\[!\[[^\]]*\]\([^\)]*\)\]\([^\)]*\)/', '', $mdContent);

            // replace standard image
            $mdContent = preg_replace('/!\[.*\]\(.*\)/', '', $mdContent);
        }

        if ($this->markdownDisableFiles) {
            // replace links to files except allowed extensions and those matching ignore patterns
            $mdContent = preg_replace_callback(
                '/\[([^\]]+)\]\((?!https?:\/\/)([^)]+)\.([a-z0-9]{1,5})\)/i',
                function ($matches) use ($ignoreRegexes) {
                    $linkText = $matches[1];
                    $fullUrl = $matches[2] . '.' . $matches[3];

                    // keep if it matches ignore patterns
                    if ($ignoreRegexes) {
                        foreach ($ignoreRegexes as $ignoreRegex) {
                            if (preg_match($ignoreRegex, $fullUrl)) {
                                return $matches[0];
                            }
                        }
                    }

                    // keep if it's an allowed extension
                    if (in_array(strtolower($matches[3]), ['md', 'jpg', 'png', 'gif', 'webp', 'avif'])) {
                        return $matches[0];
                    }

                    return ''; // remove link
                },
                $mdContent
            );

            $mdContent = str_replace('  ', ' ', $mdContent);
        }

        // remove empty links
        $mdContent = preg_replace('/\[[^\]]*\]\(\)/', '', $mdContent);

        // remove empty lines in code blocks (multi-line commands)
        $mdContent = str_replace(
            ["\\\n\n  -"],
            ["\\\n  -"],
            $mdContent
        );

        // remove empty lines in the beginning of code blocks
        $mdContent = preg_replace('/```\n{2,}/', "```\n", $mdContent);

        // apply additional fixes
        $mdContent = $this->removeEmptyLinesInLists($mdContent);
        $mdContent = $this->moveContentBeforeMainHeadingToTheEnd($mdContent);
        $mdContent = $this->fixMultilineImages($mdContent);
        $mdContent = $this->detectAndSetCodeLanguage($mdContent);

        // add "`" around "--param" inside tables
        $mdContent = preg_replace('/\| -{1,2}([a-z0-9][a-z0-9-]*) \|/i', '| `--$1` |', $mdContent);

        // remove 3+ empty lines to 2 empty lines
        $mdContent = preg_replace('/\n{3,}/', "\n\n", $mdContent);

        file_put_contents($mdFilePath, $mdContent);
    }

    /**
     * Check if URL can be stored with respect to --markdown-export-store-only-url-regex option and --allow-domain-*
     *
     * @param VisitedUrl $visitedUrl
     * @return bool
     */
    private function shouldBeUrlStored(VisitedUrl $visitedUrl): bool
    {
        $result = false;

        // by --markdown-export-store-only-url-regex
        if ($this->markdownExportStoreOnlyUrlRegex) {
            foreach ($this->markdownExportStoreOnlyUrlRegex as $storeOnlyUrlRegex) {
                if (preg_match($storeOnlyUrlRegex, $visitedUrl->url) === 1) {
                    $result = true;
                    break;
                }
            }
        } else {
            $result = true;
        }

        // by --allow-domain-* for external domains
        if ($result && $visitedUrl->isExternal) {
            $parsedUrl = ParsedUrl::parse($visitedUrl->url);
            if ($this->crawler->isExternalDomainAllowedForCrawling($parsedUrl->host)) {
                $result = true;
            } else if (($visitedUrl->isStaticFile() || $parsedUrl->isStaticFile()) && $this->crawler->isDomainAllowedForStaticFiles($parsedUrl->host)) {
                $result = true;
            } else {
                $result = false;
            }
        }

        // do not store robots.txt
        if (basename($visitedUrl->url) === 'robots.txt') {
            $result = false;
        }

        return $result;
    }

    private function getRelativeFilePathForFileByUrl(VisitedUrl $visitedUrl): string
    {
        $urlConverter = new OfflineUrlConverter(
            $this->crawler->getInitialParsedUrl(),
            ParsedUrl::parse($visitedUrl->sourceUqId ? $this->status->getUrlByUqId($visitedUrl->sourceUqId) : $this->crawler->getCoreOptions()->url),
            ParsedUrl::parse($visitedUrl->url),
            [$this->crawler, 'isDomainAllowedForStaticFiles'],
            [$this->crawler, 'isExternalDomainAllowedForCrawling'],
            // give hint about image (simulating 'src' attribute) to have same logic about dynamic images URL without extension
            $visitedUrl->contentType === Crawler::CONTENT_TYPE_ID_IMAGE ? 'src' : 'href'
        );

        $relativeUrl = $urlConverter->convertUrlToRelative(false);
        $relativeTargetUrl = $urlConverter->getRelativeTargetUrl();
        $relativePath = '';

        switch ($urlConverter->getTargetDomainRelation()) {
            case TargetDomainRelation::INITIAL_DIFFERENT__BASE_SAME:
            case TargetDomainRelation::INITIAL_DIFFERENT__BASE_DIFFERENT:
                $relativePath = ltrim(str_replace('../', '', $relativeUrl), '/ ');
                if (!str_starts_with($relativePath, '_' . $relativeTargetUrl->host)) {
                    $relativePath = '_' . $relativeTargetUrl->host . '/' . $relativePath;
                }
                break;
            case TargetDomainRelation::INITIAL_SAME__BASE_SAME:
            case TargetDomainRelation::INITIAL_SAME__BASE_DIFFERENT:
                $relativePath = ltrim(str_replace('../', '', $relativeUrl), '/ ');
                break;
        }

        return $relativePath;
    }

    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Removes empty lines between list items in markdown content while preserving list structure
     * Works with both ordered and unordered lists of any nesting level
     *
     * @param string $md
     * @return string
     */
    private function removeEmptyLinesInLists(string $md): string
    {
        $lines = explode("\n", $md);
        $result = [];
        $inList = false;
        $lastLineEmpty = false;
        $lastIndentLevel = 0;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);
            $isEmptyLine = $trimmedLine === '';

            if (preg_match('/^[ ]{0,3}[-*+][ ]|^[ ]{0,3}\d+\.[ ]|^[ ]{2,}[-*+][ ]/', $line)) {
                $inList = true;

                if ($lastLineEmpty) {
                    array_pop($result);
                }

                $result[] = $line;
                $lastLineEmpty = false;

                preg_match('/^[ ]*/', $line, $matches);
                $lastIndentLevel = strlen($matches[0]);
            } elseif ($isEmptyLine) {
                if ($inList) {
                    $lastLineEmpty = true;
                    $result[] = $line;
                } else {
                    $result[] = $line;
                    $lastLineEmpty = true;
                }
            } else {
                preg_match('/^[ ]*/', $line, $matches);
                $currentIndent = strlen($matches[0]);

                if ($inList && $currentIndent < $lastIndentLevel) {
                    $inList = false;
                }

                $result[] = $line;
                $lastLineEmpty = false;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Find the first occurrence of the main heading (<h1>, if it does not exist <h2> or <h3>) and
     * all content before it, move to the end in the section below the "---"
     *
     * @param string $md
     * @return string
     */
    private function moveContentBeforeMainHeadingToTheEnd(string $md): string
    {
        // find highest level heading that exists in the content (h1, h2 or h3)
        $headingPattern = '/^(?:# |## |### )/m';
        preg_match_all($headingPattern, $md, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return $md; // No headings found
        }

        // find lowest number of #'s used (highest level heading)
        $highestLevel = PHP_INT_MAX;
        foreach ($matches[0] as $match) {
            $level = strlen(trim($match[0], ' '));
            if ($level < $highestLevel) {
                $highestLevel = $level;
            }
        }

        // find first occurrence of the highest level heading
        $mainHeadingPattern = '/^' . str_repeat('#', $highestLevel) . ' /m';
        preg_match($mainHeadingPattern, $md, $match, PREG_OFFSET_CAPTURE);

        if (empty($match)) {
            return $md;
        }

        $headingPosition = $match[0][1];

        // extract content before the main heading
        $contentBefore = substr($md, 0, $headingPosition);
        $contentAfter = substr($md, $headingPosition);

        // if there's no content before heading, return unchanged
        if (trim($contentBefore) === '') {
            return $md;
        }

        // build final content with the content before moved to end
        return trim($contentAfter) . "\n\n---\n\n" . trim($contentBefore);
    }

    /**
     * Fixes multi-line image and link definitions in markdown to be on single line
     * Specifically handles cases where markdown image/link syntax is split across multiple lines
     *
     * @param string $md
     * @return string
     */
    private function fixMultilineImages(string $md): string
    {
        $md = str_replace(
            ["[\n![", ")\n]("],
            ["[![", ")]("],
            $md
        );

        return $md;
    }

    /**
     * Detects and sets code language for markdown code blocks that don't have language specified
     * Uses simple but reliable patterns to identify common programming languages
     *
     * @param string $md
     * @return string
     */
    private function detectAndSetCodeLanguage(string $md): string
    {
        // pattern to find code blocks without specified language
        $codeBlockPattern = '/```\s*\n((?:[^`]|`[^`]|``[^`])*?)\n```/s';

        $result = preg_replace_callback($codeBlockPattern, function ($matches) {
            $code = $matches[1];
            $detectedLang = $this->detectLanguage($code);
            return "```{$detectedLang}\n{$code}\n```";
        }, $md);

        return $result ?: $md;
    }

    /**
     * Detects programming language based on code content using characteristic patterns
     *
     * @param string $code
     * @return string
     */
    private function detectLanguage(string $code): string
    {
        $patterns = [
            'php' => [
                '/^<\?php/',              // PHP opening tag
                '/\$[a-zA-Z_]/',          // PHP variables
                '/\b(?:public|private|protected)\s+function\b/', // PHP methods
                '/\bnamespace\s+[a-zA-Z\\\\]+;/', // PHP namespace
            ],
            'javascript' => [
                '/\bconst\s+[a-zA-Z_][a-zA-Z0-9_]*\s*=/',  // const declarations
                '/\bfunction\s*\([^)]*\)\s*{/',            // function declarations
                '/\blet\s+[a-zA-Z_][a-zA-Z0-9_]*\s*=/',    // let declarations
                '/\bconsole\.log\(/',                      // console.log
                '/=>\s*{/',                                // arrow functions
            ],
            'jsx' => [
                '/return\s+\(/',                         // JSX return statements
                '/import\s+[a-zA-Z0-9_,\{\} ]+\s+from/',     // imports
                '/export\s+(default|const)/',                  // exports
            ],
            'typescript' => [
                '/:\s*(?:string|number|boolean|any)\b/',   // type annotations
                '/interface\s+[A-Z][a-zA-Z0-9_]*\s*{/',    // interfaces
                '/type\s+[A-Z][a-zA-Z0-9_]*\s*=/',        // type aliases
            ],
            'python' => [
                '/def\s+[a-zA-Z_][a-zA-Z0-9_]*\s*\([^)]*\):\s*$/', // function definitions
                '/^from\s+[a-zA-Z_.]+\s+import\b/',       // imports
                '/^if\s+__name__\s*==\s*[\'"]__main__[\'"]:\s*$/', // main guard
            ],
            'java' => [
                '/public\s+class\s+[A-Z][a-zA-Z0-9_]*/',  // class definitions
                '/System\.out\.println\(/',                // println
                '/private\s+final\s+/',                    // private final fields
            ],
            'rust' => [
                '/fn\s+[a-z_][a-z0-9_]*\s*\([^)]*\)\s*(?:->\s*[a-zA-Z<>]+\s*)?\{/', // functions
                '/let\s+mut\s+/',                         // mutable variables
                '/impl\s+[A-Z][a-zA-Z0-9_]*/',           // implementations
            ],
            'ruby' => [
                '/^require\s+[\'"][a-zA-Z0-9_\/]+[\'"]/',  // requires
                '/def\s+[a-z_][a-z0-9_]*\b/',             // method definitions
                '/\battr_accessor\b/',                     // attr_accessor
            ],
            'css' => [
                '/^[.#][a-zA-Z-_][^{]*\{/',              // selectors
                '/\b(?:margin|padding|border|color|background):\s*[^;]+;/', // common properties
                '/@media\s+/',                            // media queries
            ],
            'bash' => [
                '/^#!\/bin\/(?:bash|sh)/',               // shebang
                '/\$\([^)]+\)/',                         // command substitution
                '/(?:^|\s)(?:-{1,2}[a-zA-Z0-9]+)/',     // command options
                '/\becho\s+/',                           // echo command
                '/\|\s*grep\b/',                         // pipes and common commands
            ],
            'go' => [
                '/\bfunc\s+[a-zA-Z_][a-zA-Z0-9_]*\s*\([^)]*\)/',  // function declarations
                '/\btype\s+[A-Z][a-zA-Z0-9_]*\s+struct\b/',       // struct definitions
                '/\bpackage\s+[a-z][a-z0-9_]*\b/',                // package declarations
                '/\bif\s+err\s*!=\s*nil\b/',                      // error handling
            ],
            'csharp' => [
                '/\bnamespace\s+[A-Za-z.]+\b/',                   // namespace declarations
                '/\bpublic\s+(?:class|interface|enum)\b/',        // public types
                '/\busing\s+[A-Za-z.]+;/',                        // using statements
                '/\basync\s+Task</',                              // async methods
            ],
            'kotlin' => [
                '/\bfun\s+[a-zA-Z_][a-zA-Z0-9_]*\s*\(/',         // function declarations
                '/\bval\s+[a-zA-Z_][a-zA-Z0-9_]*:/',             // immutable variables
                '/\bvar\s+[a-zA-Z_][a-zA-Z0-9_]*:/',             // mutable variables
                '/\bdata\s+class\b/',                             // data classes
            ],
            'swift' => [
                '/\bfunc\s+[a-zA-Z_][a-zA-Z0-9_]*\s*\(/',        // function declarations
                '/\bvar\s+[a-zA-Z_][a-zA-Z0-9_]*:\s*[A-Z]/',     // typed variables
                '/\blet\s+[a-zA-Z_][a-zA-Z0-9_]*:/',             // constants
                '/\bclass\s+[A-Z][A-Za-z0-9_]*:/',               // class inheritance
            ],
            'cpp' => [
                '/\b(?:class|struct)\s+[A-Z][a-zA-Z0-9_]*\b/',   // class/struct declarations
                '/\bstd::[a-z0-9_]+/',                           // std namespace usage
                '/\b#include\s+[<"][a-z0-9_.]+[>"]/',            // includes
                '/\btemplate\s*<[^>]+>/',                        // templates
            ],
            'scala' => [
                '/\bdef\s+[a-z][a-zA-Z0-9_]*\s*\(/',            // method declarations
                '/\bcase\s+class\b/',                            // case classes
                '/\bobject\s+[A-Z][a-zA-Z0-9_]*\b/',            // objects
                '/\bval\s+[a-z][a-zA-Z0-9_]*\s*=/',             // value declarations
            ],
            'perl' => [
                '/\buse\s+[A-Z][A-Za-z:]+;/',                   // module imports
                '/\bsub\s+[a-z_][a-z0-9_]*\s*\{/',             // subroutine definitions
                '/\@[a-zA-Z_][a-zA-Z0-9_]*/',                   // array variables
            ],
            'lua' => [
                '/\bfunction\s+[a-z_][a-z0-9_]*\s*\(/',         // function definitions
                '/\blocal\s+[a-z_][a-z0-9_]*\s*=/',             // local variables
                '/\brequire\s*\(?[\'"][^\'"]+[\'"]\)?/',        // require statements
            ],
            'vb' => [
                '/\bPublic\s+(?:Class|Interface|Module)\b/',        // type declarations
                '/\bPrivate\s+Sub\s+[A-Za-z_][A-Za-z0-9_]*\(/',    // private methods
                '/\bDim\s+[A-Za-z_][A-Za-z0-9_]*\s+As\b/',         // variable declarations
                '/\bEnd\s+(?:Sub|Function|Class|If|While)\b/',      // end blocks
            ],
            'fsharp' => [
                '/\blet\s+[a-z_][a-zA-Z0-9_]*\s*=/',              // value bindings
                '/\bmodule\s+[A-Z][A-Za-z0-9_]*\s*=/',            // module definitions
                '/\btype\s+[A-Z][A-Za-z0-9_]*\s*=/',              // type definitions
                '/\bmatch\s+.*\bwith\b/',                         // pattern matching
            ],
            'powershell' => [
                '/\$[A-Za-z_][A-Za-z0-9_]*/',                     // variables
                '/\[Parameter\(.*?\)\]/',                          // parameter attributes
                '/\bfunction\s+[A-Z][A-Za-z0-9-]*/',              // function declarations
                '/\b(?:Get|Set|New|Remove)-[A-Z][A-Za-z]*/',      // common cmdlets
            ],
            'xaml' => [
                '/<Window\s+[^>]*>/',                             // WPF windows
                '/<UserControl\s+[^>]*>/',                        // user controls
                '/xmlns:(?:x|d)="[^"]+"/',                       // common namespaces
                '/<(?:Grid|StackPanel|DockPanel)[^>]*>/',        // common layout controls
            ],
            'razor' => [
                '/@(?:model|using|inject)/',                      // Razor directives
                '/@Html\.[A-Za-z]+\(/',                          // Html helpers
                '/@\{.*?\}/',                                    // code blocks
                '/<partial\s+name="[^"]+"\s*\/>/',              // partial views
            ],
            'html' => [
                '/<(html|head|body|h1|a|img|table|tr|td|ul|ol|li|script|style)[^>]*>/', // HTML tags
            ]
        ];

        $scores = [];
        foreach ($patterns as $lang => $langPatterns) {
            $scores[$lang] = 0;
            foreach ($langPatterns as $pattern) {
                $matches = preg_match_all($pattern, $code);
                if ($matches) {
                    $scores[$lang] += $matches;
                }
            }
        }

        // find language with highest score
        $maxScore = 0;
        $detectedLang = '';

        foreach ($scores as $lang => $score) {
            if ($score > $maxScore) {
                $maxScore = $score;
                $detectedLang = $lang;
            }
        }

        // return detected language or empty string if nothing was detected
        return $maxScore > 0 ? $detectedLang : '';
    }

    /**
     * Finds <table>...</table> in the given HTML, converts them to Markdown tables,
     * and replaces the original HTML tables with the generated Markdown.
     *
     * @param string $html
     * @return string
     */
    function replaceHtmlTableToMdTable(string $html): string
    {
        return preg_replace_callback(
            '/<table\b[^>]*>(.*)<\/table>/isU',
            function ($matches) {
                return $this->convertHtmlTableToMarkdown($matches[0]);
            },
            $html
        );
    }

    /**
     * Converts a single HTML table to a Markdown table.
     *
     * @param string $tableHtml
     * @return string
     */
    function convertHtmlTableToMarkdown(string $tableHtml): string
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><body>' . $tableHtml . '</body>');
        libxml_clear_errors();

        $table = $doc->getElementsByTagName('table')->item(0);
        if (!$table) {
            // if parsing fails, return the original table
            return $tableHtml;
        }

        $rows = $table->getElementsByTagName('tr');
        $tableData = [];
        $maxCols = 0;

        foreach ($rows as $row) {
            // attempt to get 'th' cells first; if none, use 'td' cells
            $cells = $row->getElementsByTagName('th');
            if ($cells->length === 0) {
                $cells = $row->getElementsByTagName('td');
            }

            $rowData = [];
            foreach ($cells as $cell) {
                $text = trim($cell->textContent);
                // basic cleanup (replace multiple whitespaces with single space)
                $rowData[] = preg_replace('/\s+/', ' ', $text);
            }

            $maxCols = max($maxCols, count($rowData));
            $tableData[] = $rowData;
        }

        // build Markdown table
        $mdTable = '';
        if (!empty($tableData)) {
            // first row is treated as header
            $header = $tableData[0];
            $mdTable .= '| ' . implode(' | ', $header) . ' |' . "<br/>\n";
            $mdTable .= '| ' . implode(' | ', array_fill(0, $maxCols, '---')) . ' |' . "<br/>\n";

            // remaining rows
            for ($i = 1; $i < count($tableData); $i++) {
                $row = array_pad($tableData[$i], $maxCols, '');
                $mdTable .= '| ' . implode(' | ', $row) . ' |' . "<br/>\n";
            }
        }

        // in $mdTable replace <script> and <style> with HTML entities versions, otherwise it would be executed in markdown and break the output
        $mdTable = preg_replace('/<script[^>]*>.*?<\/script>/is', '&lt;script&gt;&lt;/script&gt;', $mdTable);
        $mdTable = preg_replace('/<style[^>]*>.*?<\/style>/is', '&lt;style&gt;&lt;/style&gt;', $mdTable);
        $mdTable = preg_replace('/<script[^>]*>/i', '&lt;script&gt;', $mdTable);
        $mdTable = preg_replace('/<style[^>]*>/i', '&lt;style&gt;', $mdTable);

        return $mdTable;
    }

    public static function getOptions(): Options
    {
        $options = new Options();
        $options->addGroup(new Group(
            self::GROUP_MARKDOWN_EXPORTER,
            'Markdown exporter options', [
            new Option('--markdown-export-dir', '-med', 'markdownExportDirectory', Type::DIR, false, 'Path to directory where to save the markdown version of the website.', null, true),
            new Option('--markdown-export-store-only-url-regex', null, 'markdownExportStoreOnlyUrlRegex', Type::REGEX, true, 'For debug - when filled it will activate debug mode and store only URLs which match one of these PCRE regexes. Can be specified multiple times.', null, true),
            new Option('--markdown-disable-images', '-mdi', 'markdownDisableImages', Type::BOOL, false, 'Do not export and show images in markdown files. Images are enabled by default.', false, true),
            new Option('--markdown-disable-files', '-mdf', 'markdownDisableFiles', Type::BOOL, false, 'Do not export and link files other than HTML/CSS/JS/fonts/images - eg. PDF, ZIP, etc. These files are enabled by default.', false, true),
            new Option('--markdown-exclude-selector', '-mes', 'markdownExcludeSelector', Type::STRING, true, "Exclude some page content (DOM elements) from markdown export defined by CSS selectors like 'header', '.header', '#header', etc.", null, false, true),
            new Option('--markdown-replace-content', null, 'markdownReplaceContent', Type::REPLACE_CONTENT, true, "Replace text content with `foo -> bar` or regexp in PREG format: `/card[0-9]/i -> card`", null, true, true),
            new Option('--markdown-replace-query-string', null, 'markdownReplaceQueryString', Type::REPLACE_CONTENT, true, "Instead of using a short hash instead of a query string in the filename, just replace some characters. You can use simple format 'foo -> bar' or regexp in PREG format, e.g. '/([a-z]+)=([^&]*)(&|$)/i -> $1__$2'", null, true, true),
            new Option('--markdown-ignore-store-file-error', null, 'markdownIgnoreStoreFileError', Type::BOOL, false, 'Ignores any file storing errors. The export process will continue.', false, false),
        ]));
        return $options;
    }
}