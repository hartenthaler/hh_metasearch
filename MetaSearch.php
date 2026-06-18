<?php

/**
 * MetaSearch - hh_metasearch (webtrees custom module)
 *
 * Function: supports MetaSuche at https://meta.genealogy.net/.
 * see https://wiki.genealogy.net/Metasuche/neue_Schnittstelle
 *
 * Copyright (C) 2023 Hermann Hartenthaler
 * 
 * webtrees: online genealogy / web based family history software
 * Copyright (C) 2023 webtrees development team
 *                    <http://webtrees.net>
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\MetaSearch;

use DOMDocument;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\ExtCalendar\GregorianCalendar;
use Fisharebest\Localization\Translation;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Http\RequestHandlers\HomePage;
use Fisharebest\Webtrees\Http\RequestHandlers\TreePage;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Location;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function array_key_exists;
use function array_filter;
use function array_slice;
use function checkdate;
use function explode;
use function html_entity_decode;
use function implode;
use function mb_strtolower;
use function password_get_info;
use function password_hash;
use function password_verify;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function route;
use function strlen;
use function str_contains;
use function str_replace;
use function strip_tags;
use function substr;
use function trim;

final class MetaSearchParameterException extends InvalidArgumentException
{
}

final class MetaSearchParameters
{
    /**
     * @param array<int,string> $treeNames
     */
    public function __construct(
        public readonly string $key,
        public readonly string $trees,
        public readonly array $treeNames,
        public readonly string $lastname,
        public readonly string $placeid,
        public readonly string $placename,
        public readonly string $since,
        public readonly int $sinceJulianDay,
    ) {
    }

    public function isEmptySearch(): bool
    {
        return $this->lastname === '' && $this->placeid === '' && $this->placename === '';
    }

    /**
     * @return array<int,string>
     */
    public function requestedTreeNames(): array
    {
        return $this->treeNames;
    }

    public function hasTreeFilter(): bool
    {
        return $this->trees !== '';
    }
}

final class MetaSearchApiMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly MetaSearch $module)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // API endpoint: handle before the global webtrees CSRF middleware.
        return $this->module->handle($request);
    }
}

/**
 * Class MetaSearch
 */
class MetaSearch extends AbstractModule implements 
	ModuleCustomInterface, 
	ModuleConfigInterface,
	RequestHandlerInterface 
{
    use ModuleCustomTrait;
    use ModuleConfigTrait;

    /**
     * list of const for module administration
     */

    // Module title 
    public const string CUSTOM_TITLE            = 'MetaSearch';

    // Module file name
    public const string CUSTOM_MODULE           = 'hh_metasearch';

	// Author of custom module
	public const string CUSTOM_AUTHOR 		    = 'Hermann Hartenthaler';
	
	// GitHub repository
	public const string GITHUB_REPO 		    = 'hartenthaler/' . self::CUSTOM_MODULE;

    // Custom module website
    public const string CUSTOM_WEBSITE          = 'https://github.com/' . self::GITHUB_REPO . '/';
	
	// Custom module version
	public const string CUSTOM_VERSION 	        = '2.2.6.0';

	// GitHub API URL to get the information about the latest releases
	public const string GITHUB_API_LATEST_VERSION  = 'https://api.github.com/repos/'. self::GITHUB_REPO . '/releases/latest';
	public const string GITHUB_API_TAG_NAME_PREFIX = '"tag_name":"v';
	
	// Route
	protected const string ROUTE_URL 		    = '/' . self::CUSTOM_TITLE;

    // Preferences, Settings
	public const string PREF_MODULE_VERSION 	= 'module_version'; //tbd wozu?
	public const string PREF_SECRET_KEY 		= 'secret_key';
	public const string PREF_USE_HASH 			= 'use_hash';
    public const int MIN_SECRET_KEY_LENGTH      = 12;
	public const PREF_MAX_HIT_DEFAULT	        = 20;
	public const string PREF_MAX_HIT			= 'max_hit';
	public const string PREF_DATABASE_NAME		= 'database_name';                 // eg 'Ahnendatenbank Hartenthaler'
	public const string PREF_TREE_ORDER         = 'tree_order';
    private const int MAX_KEY_LENGTH            = 1024;
    private const int MAX_TEXT_LENGTH           = 255;
    private const int MAX_PLACEID_LENGTH        = 128;
    private const int MAX_TREE_LIST_LENGTH      = 1024;
    private const int MAX_TREE_NAME_LENGTH      = 255;

   /**
     * constructor
     */
    public function __construct()
    {
		// IMPORTANT - the constructor is called on *all* modules, even ones that are disabled.
        // It is also called before the webtrees framework is initialized, and so other components will not yet exist.
    }

    /**
     * initialization
     *
     * @return void
     */
    public function boot(): void
    {
		// register route
		Registry::routeFactory()->routeMap()
            ->get(static::class, self::ROUTE_URL, $this)
            ->allows(RequestMethodInterface::METHOD_POST)
            ->extras(['middleware' => [new MetaSearchApiMiddleware($this)]]);

        // Here is also a good place to register any views (templates) used by the module.
        // This command allows the module to use: view($this->name() . '::', 'fish')
        // to access the file ./resources/views/fish.phtml

		// register a namespace for the views
		View::registerNamespace($this->name(), strtr($this->resourcesFolder() . 'views' . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, '/'));
    }
	
    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @see \Fisharebest\Webtrees\Module\AbstractModule::title()
     */
    public function title(): string
    {
        return /* I18N: Name of a module. */ I18N::translate(self::CUSTOM_TITLE);
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @see \Fisharebest\Webtrees\Module\AbstractModule::description()
     */
    public function description(): string
    {
        return /* I18N: Description of this module */ I18N::translate('A custom module to support "Metasuche" in the genealogy net.');
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @see \Fisharebest\Webtrees\Module\AbstractModule::resourcesFolder()
     */
    public function resourcesFolder(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @see \Fisharebest\Webtrees\Module\ModuleCustomInterface::customModuleAuthorName()
     */
    public function customModuleAuthorName(): string
    {
        return self::CUSTOM_AUTHOR;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @see \Fisharebest\Webtrees\Module\ModuleCustomInterface::customModuleVersion()
     */
    public function customModuleVersion(): string
    {
        return self::CUSTOM_VERSION;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @see \Fisharebest\Webtrees\Module\ModuleCustomInterface::customModuleLatestVersion()
     */
    public function customModuleLatestVersion(): string
    {
        // No update URL provided.
        if (self::GITHUB_API_LATEST_VERSION === '') {
            return $this->customModuleVersion();
        }
        return Registry::cache()->file()->remember(
            $this->name() . '-latest-version',
            function (): string {
                try {
                    $client = new Client(
                        [
                        'timeout' => 3,
                        ]
                    );

                    $response = $client->get(self::GITHUB_API_LATEST_VERSION);

                    if ($response->getStatusCode() === StatusCodeInterface::STATUS_OK) {
                        $content = $response->getBody()->getContents();
                        preg_match_all('/' . self::GITHUB_API_TAG_NAME_PREFIX .
                                                '\d+\.\d+\.\d+/', $content, $matches, PREG_OFFSET_CAPTURE);

						if(!empty($matches[0]))
						{
							$version = $matches[0][0][0];
							$version = substr($version, strlen(self::GITHUB_API_TAG_NAME_PREFIX));	
						}
						else
						{
							$version = $this->customModuleVersion();
						}

                        return $version;
                    }
                } catch (GuzzleException $ex) {
                    // can't connect to the server?
                }

                return $this->customModuleVersion();
            },
            86400
        );
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     *
     * @see \Fisharebest\Webtrees\Module\ModuleCustomInterface::customModuleSupportUrl()
     */
    public function customModuleSupportUrl(): string
    {
        return self::CUSTOM_WEBSITE;
    }

    /**
     * Privacy information consumed by hh_legal_notice.
     *
     * @return array{third_party_services:list<array{name:string,url:string,country:string,description:string,data:list<string>}>,security_measures:list<string>}
     */
    public function privacyNotices(): array
    {
        return [
            'third_party_services' => [
                [
                    'name' => 'Metasuche',
                    'url' => 'https://meta.genealogy.net/',
                    'country' => 'Germany',
                    'description' => I18N::translate('The MetaSearch module receives search requests from meta.genealogy.net and returns matching result data from the enabled public family trees.'),
                    'data' => [
                        I18N::translate('Search parameters submitted by meta.genealogy.net.'),
                        I18N::translate('Matching names, places, dates, and links from enabled public family trees.'),
                        I18N::translate('Technical request metadata required to process and protect the interface.'),
                    ],
                ],
            ],
            'security_measures' => [
                I18N::translate('The MetaSearch interface is protected by a shared secret that is stored as a password hash.'),
            ],
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @param string $language
     *
     * @return array
     *
     * @see \Fisharebest\Webtrees\Module\ModuleCustomInterface::customTranslations()
     */
    public function customTranslations(string $language): array
    {
        $lang_dir   = $this->resourcesFolder() . 'lang' . DIRECTORY_SEPARATOR;
        $file       = $lang_dir . $language . '.mo';
        if (file_exists($file)) {
            return (new Translation($file))->asArray();
        } else {
            return [];
        }
    }

    /**
     * view module settings in the control panel
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        //Check update of module version
        $this->checkModuleVersionUpdate();

        $this->layout = 'layouts' . DIRECTORY_SEPARATOR . 'administration';

		$tree_list    = $this->getConfiguredTrees();
        $endpoint_url = route(static::class);

        return $this->viewResponse(
            $this->name() . '::settings',
            [
                'title'             	    => $this->title(),
                'description'  			    => $this->description(),
                self::PREF_DATABASE_NAME    => $this->getPreference(self::PREF_DATABASE_NAME, ''),      // tbd is there a better default value available?
                self::PREF_SECRET_KEY       => $this->getPreference(self::PREF_SECRET_KEY, ''),
                self::PREF_USE_HASH         => boolval($this->getPreference(self::PREF_USE_HASH, '1')),
                'endpoint_url'              => $endpoint_url,
                'query_example_url'         => $endpoint_url . (str_contains($endpoint_url, '?') ? '&' : '?') . 'key=YOUR_KEY&lastname=Muster',
                'minimum_secret_key_length' => self::MIN_SECRET_KEY_LENGTH,
                self::PREF_MAX_HIT		    => $this->getPreference(self::PREF_MAX_HIT, strval(self::PREF_MAX_HIT_DEFAULT)),
                'tree_list'                 => $tree_list,
            ]
        );
    }

    /**
     * save module settings after returning from control panel
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $save          	= Validator::parsedBody($request)->string('save', '');
        $database_name  = Validator::parsedBody($request)->string(self::PREF_DATABASE_NAME, '');;
        $new_secret_key = trim(Validator::parsedBody($request)->string('new_secret_key', ''));
		$max_hit		= Validator::parsedBody($request)->integer(self::PREF_MAX_HIT, self::PREF_MAX_HIT_DEFAULT);
        $params         = (array) $request->getParsedBody();
        
        // save the received settings to the user preferences
        if ($save === '1') {

            $key_error = false;

            if ($new_secret_key !== '') {
                if (strlen($new_secret_key) < self::MIN_SECRET_KEY_LENGTH) {
                    $message = I18N::translate('The provided authorization key is too short. Please provide a minimum length of %s characters.', I18N::number(self::MIN_SECRET_KEY_LENGTH));
                    FlashMessages::addMessage($message, 'danger');
                    $key_error = true;
                } else {
                    $this->setPreference(self::PREF_SECRET_KEY, password_hash($new_secret_key, PASSWORD_DEFAULT));
                    $message = I18N::translate('The authorization key was replaced.');
                    FlashMessages::addMessage($message, 'success');
                }
            }

            $this->setPreference(self::PREF_USE_HASH, '1');
            $this->setPreference(self::PREF_DATABASE_NAME, trim($database_name));
            $this->setPreference(self::PREF_MAX_HIT, ($max_hit > 0) ? strval($max_hit) : strval(self::PREF_MAX_HIT_DEFAULT));
            $this->postAdminActionTrees($params);
       
            // finally, show a success message
            if (!$key_error) {
                $message = I18N::translate('The preferences for the module "%s" were updated.', $this->title());
                FlashMessages::addMessage($message, 'success');
            }
		}

        return redirect($this->getConfigLink());
    }

    /**
     * save the user preferences for all parameters related to the trees
     *
     * @param array<string,mixed> $params configuration parameters
     */
    private function postAdminActionTrees(array $params): void
    {
        $available_trees = $this->all();
        $requested_order = $params['order'] ?? [];
        $order           = [];

        if (is_array($requested_order)) {
            foreach ($requested_order as $tree_name) {
                $tree_name = (string) $tree_name;

                if ($available_trees->has($tree_name)) {
                    $order[] = $tree_name;
                }
            }
        }

        foreach ($available_trees as $tree) {
            if (!in_array($tree->name(), $order, true)) {
                $order[] = $tree->name();
            }

            $this->setPreference('status-' . $tree->name(), isset($params['status-' . $tree->name()]) ? 'on' : '0');
        }

        $this->setPreference(self::PREF_TREE_ORDER, implode(',', $order));
    }

    /**
     * some trees should be used for search (order and enabled/disabled)
     * set default values in case the settings are not stored in the database yet
     *
     * @return array<string,object> of ordered objects with name and status (enabled/disabled)
     */
    private function getConfiguredTrees(): array
    {
        $all_trees = $this->all();
        $tree_list = [];
        $order     = array_filter(explode(',', $this->getPreference(self::PREF_TREE_ORDER, '')));

        foreach ($all_trees as $tree) {
            if (!in_array($tree->name(), $order, true)) {
                $order[] = $tree->name();
            }
        }

        foreach ($order as $tree_name) {
            $tree = $all_trees->get($tree_name);

            if ($tree instanceof Tree) {
                $tree_obj = (object)[];
                $tree_obj->title = $tree->title();
                $tree_obj->enabled = $this->getPreference('status-' . $tree->name(), 'on');
                $tree_list[$tree->name()] = $tree_obj;
            }
        }

        return $tree_list;
    }

    /**
     * Check if the module version is new and start update activities if needed
     *
     * @return void
     */
    public function checkModuleVersionUpdate(): void
    {
        $secret_key = $this->getPreference(self::PREF_SECRET_KEY, '');
        $use_hash   = $this->getPreference(self::PREF_USE_HASH, '');

        if ($secret_key !== '' && !password_get_info($secret_key)['algo']) {
            $this->setPreference(self::PREF_SECRET_KEY, password_hash($secret_key, PASSWORD_DEFAULT));
            $this->setPreference(self::PREF_USE_HASH, '1');

            $message = I18N::translate('The authorization key for the custom module "%s" was converted to secure hash storage.', $this->title());
            FlashMessages::addMessage($message, 'success');
        } elseif ($secret_key !== '' && $use_hash !== '1') {
            $this->setPreference(self::PREF_USE_HASH, '1');
        }

        //Update custom module version if changed
        if($this->getPreference(self::PREF_MODULE_VERSION, '') !== self::CUSTOM_VERSION) {
            $this->setPreference(self::PREF_MODULE_VERSION, self::CUSTOM_VERSION);
        }
    }

    /**
     * collect all the trees that have permission to access
     *
     * @return Collection<array-key,Tree>
     */
    public function all(): Collection
    {
        return Registry::container()->get(TreeService::class)
            ->all()
            ->filter(static fn (Tree $tree): bool => $tree->getPreference('imported') === '1')
            ->filter(static fn (Tree $tree): bool => $tree->getPreference('REQUIRE_AUTHENTICATION') !== '1');
    }

    /**
     * check if tree is a valid public tree
     * @param string $tree_name
     *
     * @return bool
     */ 
    private function isValidTree(string $tree_name): bool
	{
        return in_array($tree_name, $this->getSearchTrees(), true);
	}

    /**
     * get list of trees to be searched from preferences
     *
     * @return array
     */
    private function getSearchTrees(): array
    {
        $tree_list = [];

        foreach ($this->getConfiguredTrees() as $tree_name => $tree) {
            if ($tree->enabled === 'on') {
                $tree_list[] = $tree_name;
            }
        }

        return $tree_list;
    }

    /**
     * show error message in the front end
     *
     * @param string $text
     *
     * @return ResponseInterface
     */
    private function showErrorMessage(string $text, int $status = StatusCodeInterface::STATUS_BAD_REQUEST): ResponseInterface
	{
        $xml    = $this->createResultDocument();
        $result = $xml->documentElement;
        $error  = $xml->createElement('error');

        $this->appendTextElement($xml, $error, 'module', $this->title());
        $this->appendTextElement($xml, $error, 'message', $text);
        $result->appendChild($error);

        return $this->xmlResponse($xml, $status);
	}

    private function requestParameter(ServerRequestInterface $request, string $name): string
    {
        $body = $request->getParsedBody();

        if (is_array($body) && array_key_exists($name, $body)) {
            if (is_array($body[$name])) {
                throw new MetaSearchParameterException(I18N::translate('The parameter "%s" is invalid.', $name));
            }

            return trim((string) $body[$name]);
        }

        $query = $request->getQueryParams();

        if (array_key_exists($name, $query)) {
            if (is_array($query[$name])) {
                throw new MetaSearchParameterException(I18N::translate('The parameter "%s" is invalid.', $name));
            }

            return trim((string) $query[$name]);
        }

        return '';
    }

    private function validatedTextParameter(ServerRequestInterface $request, string $name, int $max_length): string
    {
        $value = $this->requestParameter($request, $name);

        $this->assertValidUtf8($name, $value);
        $this->assertNoControlCharacters($name, $value);
        $this->assertMaximumLength($name, $value, $max_length);

        return $value;
    }

    private function validatedPlaceIdParameter(ServerRequestInterface $request): string
    {
        $placeid = $this->validatedTextParameter($request, 'placeid', self::MAX_PLACEID_LENGTH);

        if ($placeid !== '' && preg_match('/\s/u', $placeid) === 1) {
            throw new MetaSearchParameterException(I18N::translate('The parameter "%s" is invalid.', 'placeid'));
        }

        return $placeid;
    }

    /**
     * @return array<int,string>
     */
    private function validatedTreeNames(string $trees): array
    {
        if ($trees === '') {
            return [];
        }

        $tree_names = [];
        $seen       = [];

        foreach (explode(',', $trees) as $tree_name) {
            $tree_name = trim($tree_name);

            if ($tree_name === '') {
                continue;
            }

            $this->assertNoControlCharacters('trees', $tree_name);
            $this->assertMaximumLength('trees', $tree_name, self::MAX_TREE_NAME_LENGTH);

            if (!array_key_exists($tree_name, $seen)) {
                $tree_names[]       = $tree_name;
                $seen[$tree_name]   = true;
            }
        }

        if ($tree_names === []) {
            throw new MetaSearchParameterException(I18N::translate('The parameter "%s" is invalid.', 'trees'));
        }

        return $tree_names;
    }

    private function validatedSinceJulianDay(string $since): int
    {
        if ($since === '') {
            return 0;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $since, $match) !== 1) {
            throw new MetaSearchParameterException(I18N::translate('The parameter "%s" must use the format yyyy-mm-dd.', 'since'));
        }

        $year  = (int) $match[1];
        $month = (int) $match[2];
        $day   = (int) $match[3];

        // this check is only unprecise check by year
        if ($year < 1582 || !checkdate($month, $day, $year)) {
            throw new MetaSearchParameterException(I18N::translate('The parameter "%s" is not a valid Gregorian date.', 'since'));
        }

        $calendar = new GregorianCalendar();
        $julian_day = $calendar->ymdToJd($year, $month, $day);

        if ($julian_day > Registry::timestampFactory()->now()->julianDay()) {
            throw new MetaSearchParameterException(I18N::translate('The parameter "%s" must not be in the future.', 'since'));
        }

        return $julian_day;
    }

    private function assertValidUtf8(string $name, string $value): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new MetaSearchParameterException(I18N::translate('The parameter "%s" contains invalid UTF-8.', $name));
        }
    }

    private function assertNoControlCharacters(string $name, string $value): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new MetaSearchParameterException(I18N::translate('The parameter "%s" contains invalid control characters.', $name));
        }
    }

    private function assertMaximumLength(string $name, string $value, int $max_length): void
    {
        if (strlen($value) > $max_length) {
            throw new MetaSearchParameterException(I18N::translate('The parameter "%s" is too long.', $name));
        }
    }

    private function keyFromRequest(ServerRequestInterface $request): string
    {
        return $this->validatedTextParameter($request, 'key', self::MAX_KEY_LENGTH);
    }

    private function parametersFromRequest(ServerRequestInterface $request, string $key): MetaSearchParameters
    {
        $trees     = $this->validatedTextParameter($request, 'trees', self::MAX_TREE_LIST_LENGTH);
        $lastname  = $this->validatedTextParameter($request, 'lastname', self::MAX_TEXT_LENGTH);
        $placeid   = $this->validatedPlaceIdParameter($request);
        $placename = $this->validatedTextParameter($request, 'placename', self::MAX_TEXT_LENGTH);
        $since     = $this->validatedTextParameter($request, 'since', 10);

        return new MetaSearchParameters(
            key: $key,
            trees: $trees,
            treeNames: $this->validatedTreeNames($trees),
            lastname: $lastname,
            placeid: $placeid,
            placename: $placename,
            since: $since,
            sinceJulianDay: $this->validatedSinceJulianDay($since),
        );
    }
 
    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // check update of module version
        $this->checkModuleVersionUpdate();

        // MetaSearch sends application/x-www-form-urlencoded POST data. Query parameters are accepted as fallback.
        try {
            $key = $this->keyFromRequest($request);
        } catch (MetaSearchParameterException $ex) {
            return $this->showErrorMessage($ex->getMessage());
        }

        // check key

        $error = false;
        // load secret key from preferences
        $secret_key = $this->getPreference(self::PREF_SECRET_KEY, '');
        if ($secret_key === '') {
            $error = true;
            $response = $this->showErrorMessage(I18N::translate('Access denied.'), StatusCodeInterface::STATUS_UNAUTHORIZED);
        } elseif ($key === '') {
            $error = true;
            $response = $this->showErrorMessage(I18N::translate('No key provided. For checking of the access rights, it is mandatory to provide a key as request parameter.'), StatusCodeInterface::STATUS_UNAUTHORIZED);
        } elseif (!password_verify($key, $secret_key)) {
            $error = true;
            $response = $this->showErrorMessage(I18N::translate('Key not accepted. Access denied.'), StatusCodeInterface::STATUS_UNAUTHORIZED);
        }

        if (!$error) {
            try {
                $parameters = $this->parametersFromRequest($request, $key);
            } catch (MetaSearchParameterException $ex) {
                return $this->showErrorMessage($ex->getMessage());
            }

            // check tree list
            if (!$parameters->hasTreeFilter()) {
                $tree_list = $this->getSearchTrees();
            } else {
                $tree_list = [];
                foreach ($parameters->requestedTreeNames() as $tree) {
                    if ($this->isValidTree($tree)) {
                        $tree_list[] = $tree;
                    } else {
                        $error = true;
                        $response = $this->showErrorMessage(I18N::translate('Tree %s is not a valid public tree name.', $tree));
                        break;
                    }
                }
            }

            if (!$error && (count($tree_list) === 0 || $parameters->isEmptySearch())) {
                $empty = true;
                $response = $this->render($empty, $tree_list, $parameters);
            } elseif (!$error) {
                $empty = false;
                $response = $this->render($empty, $tree_list, $parameters);
            }
        }
        return $response;
    }

    /**
     * search and generate XML
     *
     * @param bool $empty generate empty XML structure
     * @param array $tree_list list of tree names to be searched
     * @param MetaSearchParameters $parameters request parameters from MetaSearch
     *
     * @return ResponseInterface
     */
    public function render(bool $empty, array $tree_list, MetaSearchParameters $parameters): ResponseInterface
	{
        $hits = $this->searchHits($empty, $tree_list, $parameters);
        $database_name = $this->getPreference(self::PREF_DATABASE_NAME, '');
        $xml           = $this->createResultDocument();
        $result        = $xml->documentElement;

        $database_tree_list = $tree_list !== [] ? $tree_list : [''];

        foreach ($database_tree_list as $tree_name) {
            $database = $xml->createElement('database');
            $name     = $database_name !== '' ? $database_name : $this->title();
            $url      = route(HomePage::class);

            if ($tree_name !== '') {
                $name = $database_name !== '' ? $database_name . ' - ' . $tree_name : $tree_name;
                $url  = route(TreePage::class, ['tree' => $tree_name]);
            }

            $this->appendTextElement($xml, $database, 'name', $name);
            $this->appendTextElement($xml, $database, 'url', $url);

            foreach ($hits[$tree_name]['entries'] ?? [] as $entry) {
                $entry_element = $xml->createElement('entry');

                $this->appendTextElement($xml, $entry_element, 'lastname', $entry['lastname'] ?? '');
                if (($entry['firstname'] ?? '') !== '') {
                    $this->appendTextElement($xml, $entry_element, 'firstname', $entry['firstname']);
                }
                $this->appendTextElement($xml, $entry_element, 'details', $entry['details'] ?? '');
                $this->appendTextElement($xml, $entry_element, 'url', $entry['url'] ?? '');

                $database->appendChild($entry_element);
            }

            if (($hits[$tree_name]['more'] ?? false) === true) {
                $this->appendTextElement($xml, $database, 'more', 'true');
            }

            $result->appendChild($database);
        }

		return $this->xmlResponse($xml);
    }

    /**
     * @param array<int,string> $tree_list
     *
     * @return array<string,array{entries:array<int,array{lastname:string,firstname:string,details:string,url:string}>,more:bool}>
     */
    private function searchHits(bool $empty, array $tree_list, MetaSearchParameters $parameters): array
    {
        $hits = [];

        foreach ($tree_list as $tree_name) {
            $hits[$tree_name] = [
                'entries' => [],
                'more'    => false,
            ];
        }

        if ($empty || $parameters->lastname === '') {
            return $hits;
        }

        $trees   = $this->all();
        $max_hit = (int) $this->getPreference(self::PREF_MAX_HIT, strval(self::PREF_MAX_HIT_DEFAULT));
        $max_hit = $max_hit > 0 ? $max_hit : self::PREF_MAX_HIT_DEFAULT;

        foreach ($tree_list as $tree_name) {
            $tree = $trees->get($tree_name);

            if ($tree instanceof Tree) {
                $hits[$tree_name] = $this->searchTree($tree, $parameters, $max_hit);
            }
        }

        return $hits;
    }

    /**
     * @return array{entries:array<int,array{lastname:string,firstname:string,details:string,url:string}>,more:bool}
     */
    private function searchTree(Tree $tree, MetaSearchParameters $parameters, int $max_hit): array
    {
        $entries = [];
        $seen    = [];
        $mapper  = Registry::individualFactory()->mapper($tree);

        $query = DB::table('individuals')
            ->where('individuals.i_file', '=', $tree->id())
            ->select(['individuals.*']);

        if ($parameters->lastname !== '') {
            $query
                ->join('name', static function ($join): void {
                    $join
                        ->on('name.n_file', '=', 'individuals.i_file')
                        ->on('name.n_id', '=', 'individuals.i_id');
                })
                ->where(static function ($query) use ($parameters): void {
                    $query
                        ->where('name.n_surn', '=', $parameters->lastname)
                        ->orWhere('name.n_surname', '=', $parameters->lastname)
                        ->orWhere('name.n_surn', DB::iLike(), '%' . $parameters->lastname . '%');
                })
                ->orderBy('name.n_sort');
        } else {
            $query->orderBy('individuals.i_id');
        }

        foreach ($query->cursor() as $row) {
            $individual = $mapper($row);
            $seen_key   = (string) $individual;

            if (array_key_exists($seen_key, $seen)) {
                continue;
            }

            $seen[$seen_key] = true;

            if (!$individual->canShow(Auth::PRIV_PRIVATE) || !$individual->canShowName(Auth::PRIV_PRIVATE)) {
                continue;
            }

            $entry = $this->publicIndividualEntry($individual, $parameters->lastname, $parameters->placeid, $parameters->placename, $parameters->sinceJulianDay);

            if ($entry === null) {
                continue;
            }

            $entries[] = $entry;

            if (count($entries) > $max_hit) {
                return [
                    'entries' => array_slice($entries, 0, $max_hit),
                    'more'    => true,
                ];
            }
        }

        return [
            'entries' => $entries,
            'more'    => false,
        ];
    }

    /**
     * @return array{lastname:string,firstname:string,details:string,url:string}
     */
    private function publicIndividualEntry(Individual $individual, string $lastname, string $placeid, string $placename, int $since_julian_day): array|null
    {
        if ($placeid !== '' && !$this->individualHasPlaceid($individual, $placeid)) {
            return null;
        }

        if ($placename !== '' && !$this->individualHasPlacename($individual, $placename)) {
            return null;
        }

        if ($since_julian_day !== 0 && !$this->individualChangedSince($individual, $since_julian_day)) {
            return null;
        }

        foreach ($individual->facts(['NAME'], false, Auth::PRIV_PRIVATE) as $fact) {
            $name = $this->namePartsFromFact($fact);

            if ($lastname === '' || $this->containsLastname($name['search_lastnames'], $lastname)) {
                return [
                    'lastname'  => $this->cleanXmlText($name['lastname']),
                    'firstname' => $this->cleanXmlText($name['firstname']),
                    'details'   => $this->individualDetails($individual),
                    'url'       => $individual->url(),
                ];
            }
        }

        return null;
    }

    private function individualHasPlaceid(Individual $individual, string $placeid): bool
    {
        $checked_locations = [];

        foreach ($individual->facts([], false, Auth::PRIV_PRIVATE) as $fact) {
            if ($this->factPlaceHasGovId($fact, $placeid)) {
                return true;
            }

            foreach ($this->placeLocationXrefsFromFact($fact) as $xref) {
                if (array_key_exists($xref, $checked_locations)) {
                    continue;
                }

                $checked_locations[$xref] = true;

                if ($this->locationHasGovId($individual->tree(), $xref, $placeid, $checked_locations)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function factPlaceHasGovId(Fact $fact, string $placeid): bool
    {
        foreach ($this->placeBlocksFromFact($fact) as $place_block) {
            if (preg_match('/\n3 _GOV +' . preg_quote($placeid, '/') . '[^\S\r\n]*(?:\r?\n|$)/u', $place_block) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function placeBlocksFromFact(Fact $fact): array
    {
        $count = preg_match_all('/\n2 PLAC\b[^\n\r]*(?:\r?\n[3-9] [^\n\r]*)*/u', $fact->gedcom(), $matches);

        if ($count === false || $count === 0) {
            return [];
        }

        return $matches[0];
    }

    /**
     * @return array<int,string>
     */
    private function placeLocationXrefsFromFact(Fact $fact): array
    {
        $xrefs = [];

        foreach ($this->placeBlocksFromFact($fact) as $place_block) {
            $count = preg_match_all('/\n3 _LOC @([^@\r\n]+)@/u', $place_block, $matches);

            if ($count !== false && $count > 0) {
                foreach ($matches[1] as $xref) {
                    $xrefs[] = $xref;
                }
            }
        }

        return $xrefs;
    }

    /**
     * @param array<string,bool> $checked_locations
     */
    private function locationHasGovId(Tree $tree, string $xref, string $placeid, array &$checked_locations): bool
    {
        $location = Registry::locationFactory()->make($xref, $tree);

        if (!$location instanceof Location || !$location->canShow(Auth::PRIV_PRIVATE)) {
            return false;
        }

        foreach ($location->facts(['_GOV'], false, Auth::PRIV_PRIVATE) as $fact) {
            if (trim($fact->value()) === $placeid) {
                return true;
            }
        }

        foreach ($this->locationXrefsFromRecord($location) as $parent_xref) {
            if (array_key_exists($parent_xref, $checked_locations)) {
                continue;
            }

            $checked_locations[$parent_xref] = true;

            if ($this->locationHasGovId($tree, $parent_xref, $placeid, $checked_locations)) {
                return true;
            }
        }

        return false;
    }

    private function individualChangedSince(Individual $individual, int $since_julian_day): bool
    {
        foreach ($individual->facts(['CHAN'], false, Auth::PRIV_PRIVATE) as $fact) {
            $date = $fact->date();

            if ($date->isOK() && $date->maximumJulianDay() > $since_julian_day) {
                return true;
            }
        }

        return false;
    }

    private function individualHasPlacename(Individual $individual, string $placename): bool
    {
        $checked_locations = [];

        foreach ($individual->facts([], false, Auth::PRIV_PRIVATE) as $fact) {
            $place = trim($fact->attribute('PLAC'));

            if ($place !== '' && $this->containsPlaceName($place, $placename)) {
                return true;
            }

            foreach ($this->locationXrefsFromFact($fact) as $xref) {
                if (array_key_exists($xref, $checked_locations)) {
                    continue;
                }

                $checked_locations[$xref] = true;

                if ($this->locationHasPlacename($individual->tree(), $xref, $placename, $checked_locations)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function locationXrefsFromFact(Fact $fact): array
    {
        $count = preg_match_all('/\n[23] _LOC @([^@\r\n]+)@/u', $fact->gedcom(), $matches);

        if ($count === false || $count === 0) {
            return [];
        }

        return $matches[1];
    }

    /**
     * @param array<string,bool> $checked_locations
     */
    private function locationHasPlacename(Tree $tree, string $xref, string $placename, array &$checked_locations): bool
    {
        $location = Registry::locationFactory()->make($xref, $tree);

        if (!$location instanceof Location || !$location->canShow(Auth::PRIV_PRIVATE)) {
            return false;
        }

        foreach ($location->facts(['NAME'], false, Auth::PRIV_PRIVATE) as $fact) {
            if ($this->containsPlaceName($fact->value(), $placename)) {
                return true;
            }
        }

        foreach ($this->locationXrefsFromRecord($location) as $parent_xref) {
            if (array_key_exists($parent_xref, $checked_locations)) {
                continue;
            }

            $checked_locations[$parent_xref] = true;

            if ($this->locationHasPlacename($tree, $parent_xref, $placename, $checked_locations)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function locationXrefsFromRecord(Location $location): array
    {
        $xrefs = [];

        foreach ($location->facts(['_LOC'], false, Auth::PRIV_PRIVATE) as $fact) {
            if (preg_match('/^@([^@\r\n]+)@$/u', $fact->value(), $match) === 1) {
                $xrefs[] = $match[1];
            }
        }

        return $xrefs;
    }

    private function individualDetails(Individual $individual): string
    {
        $details = [];

        $birth = $this->lifeEventDetail($individual, Gedcom::BIRTH_EVENTS, I18N::translate('Birth'));
        if ($birth !== '') {
            $details[] = $birth;
        }

        $death = $this->lifeEventDetail($individual, Gedcom::DEATH_EVENTS, I18N::translate('Death'));
        if ($death !== '') {
            $details[] = $death;
        }

        return implode('; ', $details);
    }

    /**
     * @param array<int,string> $events
     */
    private function lifeEventDetail(Individual $individual, array $events, string $label): string
    {
        foreach ($events as $event) {
            foreach ($individual->facts([$event], false, Auth::PRIV_PRIVATE) as $fact) {
                $date = $fact->date();

                if ($date->isOK()) {
                    return $this->cleanXmlText($label . ' ' . $date->display($individual->tree()));
                }
            }
        }

        return '';
    }

    /**
     * @return array{lastname:string,firstname:string,search_lastnames:array<int,string>}
     */
    private function namePartsFromFact(Fact $fact): array
    {
        $name_value = $fact->value();
        $surn       = trim($fact->attribute('SURN'));
        $givn       = trim($fact->attribute('GIVN'));

        $search_lastnames = $surn !== '' ? $this->lastnameCandidatesFromSurn($surn) : $this->surnamesFromNameValue($name_value);
        $lastname         = $surn !== '' ? $surn : $this->fullSurnameFromNameValue($name_value);

        if ($lastname !== '') {
            $search_lastnames[] = $lastname;
        }

        return [
            'lastname'         => $lastname,
            'firstname'        => $givn !== '' ? $givn : $this->givenNamesFromNameValue($name_value),
            'search_lastnames' => $search_lastnames,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function lastnameCandidatesFromSurn(string $surn): array
    {
        $lastnames = [$surn];

        foreach (explode(',', $surn) as $lastname) {
            $lastname = trim($lastname);

            if ($lastname !== '') {
                $lastnames[] = $lastname;
            }
        }

        return $lastnames;
    }

    private function containsLastname(array $haystack, string $needle): bool
    {
        $needle = $this->normalizedText($needle);

        foreach ($haystack as $value) {
            if ($this->normalizedText($value) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function containsPlaceName(string $haystack, string $needle): bool
    {
        $haystack = $this->normalizedPlaceText($haystack);
        $needle   = $this->normalizedPlaceText($needle);

        return $needle !== '' && str_contains($haystack, $needle);
    }

    private function normalizedText(string $text): string
    {
        return mb_strtolower($this->cleanXmlText($text), 'UTF-8');
    }

    private function normalizedPlaceText(string $text): string
    {
        $text = str_replace(';', ',', $this->normalizedText($text));
        $text = preg_replace('/\s*,\s*/u', ', ', $text) ?? '';

        return trim($text);
    }

    /**
     * @return array<int,string>
     */
    private function surnamesFromNameValue(string $name_value): array
    {
        if (preg_match_all('/\/([^\/]*)\//u', $name_value, $matches) !== 1) {
            return [];
        }

        $surnames = [];

        foreach ($matches[1] as $surname) {
            $surname    = preg_replace('/^(?:[a-z]+ |[a-z]+\' ?|\'[a-z]+ )+/u', '', $surname) ?? '';
            $surnames[] = trim($surname);
        }

        return $surnames;
    }

    private function fullSurnameFromNameValue(string $name_value): string
    {
        if (preg_match('/\/.*\//u', $name_value, $match) !== 1) {
            return '';
        }

        return trim(str_replace('/', '', $match[0]));
    }

    private function givenNamesFromNameValue(string $name_value): string
    {
        $given_names = preg_replace('/ ?\/.*\/ ?/u', ' ', $name_value) ?? '';
        $given_names = preg_replace('/ ?".+"/u', ' ', $given_names) ?? '';
        $given_names = preg_replace('/ {2,}/u', ' ', $given_names) ?? '';

        return trim($given_names);
    }

    private function cleanXmlText(string $text): string
    {
        $text = strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    private function createResultDocument(): DOMDocument
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $xml->appendChild($xml->createElement('result'));

        return $xml;
    }

    private function appendTextElement(DOMDocument $xml, \DOMNode $parent, string $name, string $value): void
    {
        $element = $xml->createElement($name);
        $element->appendChild($xml->createTextNode($value));
        $parent->appendChild($element);
    }

    private function xmlResponse(DOMDocument $xml, int $status = StatusCodeInterface::STATUS_OK): ResponseInterface
    {
        return Registry::responseFactory()->response(
            $xml->saveXML(),
            $status,
            ['content-type' => 'text/xml; charset=UTF-8']
        );
    }
}
