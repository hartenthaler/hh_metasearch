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

use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Localization\Translation;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function substr;
use function password_verify;
use function explode;

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
    public const CUSTOM_TITLE           = 'MetaSearch';

    // Module file name
    public const CUSTOM_MODULE          = 'hh_metasearch';

	// Author of custom module
	public const CUSTOM_AUTHOR 		    = 'Hermann Hartenthaler';
	
	// GitHub repository
	public const GITHUB_REPO 		    = 'hartenthaler/' . self::CUSTOM_MODULE;

    // Custom module website
    public const CUSTOM_WEBSITE         = 'https://github.com/' . self::GITHUB_REPO . '/';
	
	// Custom module version
	public const CUSTOM_VERSION 	    = '2.1.18.0';

	// GitHub API URL to get the information about the latest releases
	public const GITHUB_API_LATEST_VERSION  = 'https://api.github.com/repos/'. self::GITHUB_REPO . '/releases/latest';
	public const GITHUB_API_TAG_NAME_PREFIX = '"tag_name":"v';
	
	// Route
	protected const ROUTE_URL 		    = '/' . self::CUSTOM_TITLE;

    // Preferences, Settings
	public const PREF_MODULE_VERSION 	= 'module_version'; //tbd wozu?
	public const PREF_SECRET_KEY 		= 'secret_key';
	public const PREF_USE_HASH 			= 'use_hash';
	public const PREF_MAX_HIT_DEFAULT	= 20;
	public const PREF_MAX_HIT			= 'max_hit';
	public const PREF_DATABASE_NAME		= 'database_name';                      // eg 'Ahnendatenbank Hartenthaler'
	public const PREF_DATABASE_URL		= 'database_url';                       // eg 'https://ahnen.hartenthaler.eu'


	// Alert tpyes
	public const ALERT_DANGER           = 'alert_danger';
	public const ALERT_SUCCESS          = 'alert_success';

   /**
     * constructor
     */
    public function __construct()
    {
		// IMPORTANT - the constructor is called on *all* modules, even ones that are disabled.
        // It is also called before the webtrees framework is initialised, and so other components will not yet exist.
	    $responseFactory = app(ResponseFactoryInterface::class);
        $this->meta_search_service = new MetaSearchService($responseFactory);
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
            ->allows(RequestMethodInterface::METHOD_POST);
			
        // Here is also a good place to register any views (templates) used by the module.
        // This command allows the module to use: view($this->name() . '::', 'fish')
        // to access the file ./resources/views/fish.phtml

		// register a namespace for the views
		View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
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
        return /* I18N: Name of a module. */ I18N::translate('MetaSearch');
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
        return /* I18N: Description of this module */ I18N::translate('A custom module to support "Metasuche" of CompGen.');
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
     * view module settings in control panel
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

		$tree_list = [];
        $all_trees = $this->all();

        foreach($all_trees as $tree) {
            $treeObj = (object)[];
            $treeObj->title = $tree->title();
            $treeObj->enabled = $this->getPreference('status-' . $tree->name(), 'on');
            $tree_list[$tree->name()] = $treeObj;
        }
		//tbd nur öffentlich sichtbare rausfiltern

        return $this->viewResponse(
            $this->name() . '::settings',
            [
                'title'             	    => $this->title(),
				'description'  			    => $this->description(),
                self::PREF_DATABASE_NAME    => $this->getPreference(self::PREF_DATABASE_NAME, ''),      // tbd is there a better default value available?
                self::PREF_DATABASE_URL     => $this->getPreference(self::PREF_DATABASE_URL, ''),       // tbd is there a better default value available?
                self::PREF_SECRET_KEY       => $this->getPreference(self::PREF_SECRET_KEY, ''),
                self::PREF_USE_HASH         => boolval($this->getPreference(self::PREF_USE_HASH, '1')),
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
        $database_url   = Validator::parsedBody($request)->string(self::PREF_DATABASE_URL, '');;
        $use_hash       = Validator::parsedBody($request)->boolean(self::PREF_USE_HASH, false);
        $new_secret_key = Validator::parsedBody($request)->string('new_secret_key', '');
		$max_hit		= Validator::parsedBody($request)->integer(self::PREF_MAX_HIT, self::PREF_MAX_HIT_DEFAULT);
        
        // save the received settings to the user preferences
        if ($save === '1') {

            $new_key_error = false;

            // if no new secret key is provided
			if($new_secret_key === '') {
				// if use hash changed from true to false, reset key (hash cannot be used any more)
				if(boolval($this->getPreference(self::PREF_USE_HASH, '0')) && !$use_hash) {
					$this->setPreference(self::PREF_SECRET_KEY, '');
				}
				// if use hash changed from false to true, take old key (for planned encryption) and save as hash
				elseif(!boolval($this->getPreference(self::PREF_USE_HASH, '0')) && $use_hash) {
					$new_secret_key = $this->getPreference(self::PREF_SECRET_KEY, '');
                    $hash_value = password_hash($new_secret_key, PASSWORD_BCRYPT);
                    $this->setPreference(self::PREF_SECRET_KEY, $hash_value);
				}
                // if no new secret key and no changes in hashing, do nothing
			}
			// if new secret key is too short
			elseif(strlen($new_secret_key)<8) {
				$message = I18N::translate('The provided secret key is too short. Please provide a minimum length of 8 characters.');
				FlashMessages::addMessage($message, 'danger');
                $new_key_error = true;				
			}
			// if new secret key does not escape correctly
			elseif($new_secret_key !== e($new_secret_key)) {
				$message = I18N::translate('The provided secret key contains characters, which are not accepted. Please provide a different key.');
				FlashMessages::addMessage($message, 'danger');				
                $new_key_error = true;		
            }
			// if new secret key shall be stored with a hash, create and save hash
			elseif($use_hash) {
				$hash_value = password_hash($new_secret_key, PASSWORD_BCRYPT);
				$this->setPreference(self::PREF_SECRET_KEY, $hash_value);
			}
            // otherwise, simply store the new secret key
			else {
				$this->setPreference(self::PREF_SECRET_KEY, $new_secret_key);
			}

            // save settings to preferences
            if(!$new_key_error) {
                $this->setPreference(self::PREF_USE_HASH, $use_hash ? '1' : '0');
            }
            $this->setPreference(self::PREF_DATABASE_NAME, trim($database_name));
            $this->setPreference(self::PREF_DATABASE_URL, trim($database_url));
            $this->setPreference(self::PREF_MAX_HIT, ($max_hit > 0) ? strval($max_hit) : strval(self::PREF_MAX_HIT_DEFAULT));
            //$this->postAdminActionTrees($params);
       
            // finally, show a success message
			$message = I18N::translate('The preferences for the module "%s" were updated.', $this->title());
			FlashMessages::addMessage($message, 'success');	
		}

        return redirect($this->getConfigLink());
    }

    /**
     * save the user preferences for all parameters related to the trees
     *
     * @param array $params configuration parameters
     */
    private function postAdminActionTrees(array $params)
    {
        $order = implode(",", $params['order']);
        $this->setPreference('order', $order);
        foreach ($this->all() as $tree) {
            $this->setPreference('status-' . $tree, '0');
        }
        foreach ($params as $key => $value) {
            if (str_starts_with($key, 'status-')) {
                $this->setPreference($key, $value);
            }
        }
    }

    /**
     * some trees should be used for search (order and enabled/disabled)
     * set default values in case the settings are not stored in the database yet
     *
     * @return array<string,object> of ordered objects with name and status (enabled/disabled)
     */
    private function getUsedTrees(): array
    {
        $listTrees = $this->all();
        $orderDefault = implode(',', $listFamilyParts);
        $order = explode(',', $this->getPreference('order', $orderDefault));

        if (count($listFamilyParts) > count($order)) {
            $this->addFamilyParts($listFamilyParts, $order);
        }

        $shownParts = [];
        foreach ($order as $efp) {
            $efpObj = (object)[];
            $efpObj->name = ExtendedFamilySupport::translateFamilyPart($efp);
            $efpObj->generation = ExtendedFamilySupport::formatGeneration($efp);
            $efpObj->enabled = $this->getPreference('status-' . $efp, 'on');
            $shownParts[$efp] = $efpObj;
        }
        return $shownParts;
    }

    /**
     * Check if module version is new and start update activities if needed
     *
     * @return void
     */
    public function checkModuleVersionUpdate(): void
    {
 		//If secret key is already stored and secret key hashing preference is not available (i.e. before module version v3.0.1) 
        if($this->getPreference(self::PREF_SECRET_KEY, '') !== '' && $this->getPreference(self::PREF_USE_HASH, '') === '') {

			//Set secret key hashing to false
			$this->setPreference(self::PREF_USE_HASH, '0');

            //Show flash message for update of preferences
            $message = I18N::translate('The preferences for the custom module "%s" were sucessfully updated to the new module version %s.', $this->title(), self::CUSTOM_VERSION);
            FlashMessages::addMessage($message, 'success');	
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
        return Registry::cache()->array()->remember('all-trees', static function (): Collection {
            // All trees
            $query = DB::table('gedcom')
                ->leftJoin('gedcom_setting', static function (JoinClause $join): void {
                    $join->on('gedcom_setting.gedcom_id', '=', 'gedcom.gedcom_id')
                        ->where('gedcom_setting.setting_name', '=', 'title');
                })
                ->where('gedcom.gedcom_id', '>', 0)
                ->select([
                    'gedcom.gedcom_id AS tree_id',
                    'gedcom.gedcom_name AS tree_name',
                    'gedcom_setting.setting_value AS tree_title',
                ])
                ->orderBy('gedcom.sort_order')
                ->orderBy('gedcom_setting.setting_value');

            return $query
                ->get()
                ->mapWithKeys(static function (object $row): array {
                    return [$row->tree_name => Tree::rowMapper()($row)];
                });
        });
    }

    /**
     * check if tree is a valid public tree
     * @param string $tree_name
     *
     * @return bool
     */ 
    private function isValidTree(string $tree_name): bool
	{
		$find_tree = $this->all()->first(static function (Tree $tree) use ($tree_name): bool {
            return $tree->name() === $tree_name;
        });
		
		$is_valid_tree = $find_tree instanceof Tree;
		
		if ($is_valid_tree) {
            // tbd check if this is a public tree
            $is_valid_tree = true;
        }
		
		return $is_valid_tree;
	}

    /**
     * get list of trees to be searched from preferences
     *
     * @return array
     */
    private function getSearchTrees(): array
    {
        $tree_list = [];
        $tree_list[] = 'kennedy';   // tbd
        return $tree_list;
    }

    /**
     * check if date parameter is well formatted and is a valid date in the gregorian calendar
     * return julian date
     *
     * @param string $date has format like "YYYY-MM-DD"
     *
     * @return int
     */
    private function wellFormatedDate(string $date): int
    {
        $jd = 0;
        if (false) {            // tbd check format YYYY-MM-DD (4 digits - 2 digits - 2 digits)
                                // 1582 <= YYYY <= actual year
                                // 0 < MM <= 12
                                // 0 < DD <= 31
                                // can be converted to jd and is less or equal actual date
            $jd = 1;
        }
        return $jd;
    }

    /**
     * show error message in the front end
     *
     * @param string $text
     *
     * @return ResponseInterface
     */
    private function showErrorMessage(string $text): ResponseInterface
	{
		return $this->viewResponse($this->name() . '::alert', [
            'title'        	=> 'Error',
			'tree'			=> null,
			'alert_type'    => MetaSearch::ALERT_DANGER,
			'module_name'	=> $this->title(),
			'text'  	   	=> $text,
		]);	 
	}
 
	/**
     * show success message in the front end
     *
     * @param string $text
     *
     * @return ResponseInterface
     */
	private function showSuccessMessage(string $text): ResponseInterface
	{		
	   return $this->viewResponse($this->name() . '::alert', [
		   'title'        	=> 'Success',
		   'tree'			=> null,
		   'alert_type'     => MetaSearch::ALERT_SUCCESS,
		   'module_name'	=> $this->title(),
		   'text'  	   	    => $text,
	   ]);	 
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

        // fetch URL parameters
        $key        = Validator::queryParams($request)->string('key', '');
        $trees      = trim(Validator::queryParams($request)->string('trees', ''));
        $lastname   = trim(Validator::queryParams($request)->string('lastname', ''));
        $placename  = trim(Validator::queryParams($request)->string('placename',''));
        $placeid    = trim(Validator::queryParams($request)->string('placeid',  ''));
        $since      = trim(Validator::queryParams($request)->string('since',  ''));

        // debug
        //$response = $this->showSuccessMessage(I18N::translate('key="%s" trees="%s" lastname="%s" placename="%s" placeid="%s" since="%s"',$key,$trees,$lastname,$placename,$placeid,$since));

        // check key

        $error = false;
        // load secret key from preferences
        $secret_key = $this->getPreference(self::PREF_SECRET_KEY, '');
        if ($secret_key <> '') {
            // error if key is empty and key is defined in database
            if ($key === '') {
                $error = true;
                $response = $this->showErrorMessage(I18N::translate('No key provided. For checking of the access rights, it is mandatory to provide a key as parameter in the URL.'));
            } // error if no hashing and key is not valid
            elseif (!boolval($this->getPreference(self::PREF_USE_HASH, '0')) && ($key !== $secret_key)) {
                $error = true;
                $response = $this->showErrorMessage(I18N::translate('Key not accepted. Access denied.'));
            } // error if hashing and key does not fit to hash
            elseif (boolval($this->getPreference(self::PREF_USE_HASH, '0')) && (!password_verify($key, $secret_key))) {
                $error = true;
                $response = $this->showErrorMessage(I18N::translate('Key (encrypted) not accepted. Access denied.'));
            }
        }

        if (!$error) {
            // check tree list
            if ($trees === '') {
                $tree_list = $this->getSearchTrees();
            } else {
                $tree_list = [];
                $tree_parameters = explode(',', $trees);
                foreach ($tree_parameters as $tree) {
                    $tree = trim($tree);
                    if ($this->isValidTree($tree)) {
                        $tree_list[] = $tree;
                    } else {
                        $response = $this->showErrorMessage(I18N::translate('Tree %s is not a valid public tree name.', $tree));
                    }
                }
            }

            if ((count($tree_list) == 0) or ($lastname = '' and $placename = '' and $placeid = '')) {
                $empty = true;
                $response = $this->render($empty, $tree_list, $lastname, $placename, $placeid, 0);
            } else {
                $empty = false;
                $response = $this->render($empty, $tree_list, $lastname, $placename, $placeid, $this->wellFormatedDate($since));
            }
        }
        return $response;
    }

    /**
     * search and generate JSON
     *
     * @param bool $empty generate empty JSON structure
     * @param array $tree_list list of tree names to be searched
     * @param string $lastname last name of an individual to be searched (SURN in all INDI:NAME record)
     * @param string $placename piece of place name (ie PLAC) to be searched in all events related to an INDI, like INDI:BIRT, INDI:DEAT, ...
     * @param string $placeid GOV-Id to be searched in all _LOC records (ie _LOC:_GOV) in all shared location records related to an PLAC
     * @param int $since_jd check if julian date of CHAN is greater than this value
     *
     * @return ResponseInterface
     */
    public function render(bool $empty, array $tree_list, string $lastname, string $placename, string $placeid, int $since_jd): ResponseInterface
	{
		// tbd Content-Type muss auf 'text/xml' gesetzt werden
		// tbd statt den Testwerten hier die Trefferliste nach der Suche übergeben
        $hits = [];                         // array of objects per tree; first index is tree name
        $hits_tree = (object)[];            // object containing hits in one tree
        $entries = [];                      // array of hits in one tree
        $entry = (object)[];                // hit
        $entry->lastname = 'Hartenthaler';
        $entry->firstname = 'Hermann';
        $entry->details = '* 1957 Ennetach';
        $entry->url = 'I318';
        $entries[] = $entry;
        $hits_tree->entries = $entries;
        $hits_tree->more = false;
        $hits['kennedy'] = $hits_tree;

		return $this->viewResponse($this->name() . '::json', [
            'tree'			=> null,
            'title'         => '',
            'database_name' => $this->getPreference(self::PREF_DATABASE_NAME, ''),
            'database_url'  => $this->getPreference(self::PREF_DATABASE_URL, ''),
            'empty'         => $empty,
            'hits'          => $hits,
		]);		
    }
}