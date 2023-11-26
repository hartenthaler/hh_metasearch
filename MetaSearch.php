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
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToWriteFile;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function substr;

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
    public const CUSTOM_TITLE       = 'MetaSearch';

    // Module file name
    public const CUSTOM_MODULE      = 'hh_metasearch';

    // Module description
    public const CUSTOM_DESCRIPTION = 'A custom module to support "Metasuche" of CompGen.';

	// Author of custom module
	public const CUSTOM_AUTHOR 		= 'Hermann Hartenthaler';
	
	// GitHub repository
	public const GITHUB_REPO 		= 'hartenthaler/' . self::CUSTOM_MODULE;

    // Custom module website
    public const CUSTOM_WEBSITE     = 'https://github.com/' . self::GITHUB_REPO . '/';
	
	//Custom module version
	public const CUSTOM_VERSION 	= '2.1.18.0';

	// GitHub API URL to get the information about the latest releases
	public const GITHUB_API_LATEST_VERSION  = 'https://api.github.com/repos/'. self::GITHUB_REPO . '/releases/latest';
	public const GITHUB_API_TAG_NAME_PREFIX = '"tag_name":"v';
	
	// Route
	protected const ROUTE_URL 		= '/' . self::CUSTOM_TITLE; 

    // Prefences, Settings
	public const PREF_MODULE_VERSION 	= 'module_version'; //tbd wozu?
	public const PREF_SECRET_KEY 		= "secret_key";
	public const PREF_USE_HASH 			= "use_hash";
	public const PREF_MAX_HIT_DEFAULT	= 20;
	public const PREF_MAX_HIT			= self::PREF_MAX_HIT_DEFAULT;


	// Alert tpyes
	public const ALERT_DANGER = 'alert_danger';
	public const ALERT_SUCCESS = 'alert_success';

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
        return /* I18N: Description of this module */ I18N::translate(self::CUSTOM_DESCRIPTION);
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
                        preg_match_all('/' . self::GITHUB_API_TAG_NAME_PREFIX . '\d+\.\d+\.\d+/', $content, $matches, PREG_OFFSET_CAPTURE);

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
                    // Can't connect to the server?
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
            $tree_list[$tree->name()] = $tree->name() . ' (' . $tree->title() . ')';
        }
		//tbd nur öffentlich sichtbare rausfiltern

        return $this->viewResponse(
            $this->name() . '::settings',
            [
                'title'             	=> $this->title(),
				'description'  			=> $this->description();
                'tree_list'             => $tree_list,
				self::PREF_MAX_HIT		=> $this->getPreference(self::PREF_MAX_HIT, self::PREF_MAX_HIT_DEFAULT),
				self::PREF_SECRET_KEY   => $this->getPreference(self::PREF_SECRET_KEY, ''),
				self::PREF_USE_HASH     => boolval($this->getPreference(self::PREF_USE_HASH, '1')),
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
        $use_hash       = Validator::parsedBody($request)->boolean(self::PREF_USE_HASH, false);
        $new_secret_key = Validator::parsedBody($request)->string('new_secret_key', '');
		$max_hit		= Validator::parsedBody($request)->int(self::PREF_MAX_HIT, 20);
        
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
			$this->setPreference('max_hit', $use_hash ? '1' : '0');
       
            // finally, show a success message
			$message = I18N::translate('The preferences for the module "%s" were updated.', $this->title());
			FlashMessages::addMessage($message, 'success');	
		}

        return redirect($this->getConfigLink());
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
     * All the trees that the current user has permission to access.
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
     * Check if tree is a valid tree
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
            $this->download_tree = $find_tree;
        }
		
		return $is_valid_tree;
	 }
	 
	 /**
     * Show error message in the front end
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
     * Show success message in the front end
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
		//Load secret key from preferences
        $secret_key = $this->getPreference(self::PREF_SECRET_KEY, ''); 
   		
		$key       = Validator::queryParams($request)->string('key', '');
		$tree_name = Validator::queryParams($request)->string('tree', $this->getPreference(self::PREF_DEFAULT_TREE_NAME, ''));

		//Check update of module version
        $this->checkModuleVersionUpdate();
		
        //Error if tree name is not valid
        if (!$this->isValidTree($tree_name)) {
			$response = $this->showErrorMessage(I18N::translate('Tree not found') . ': ' . $tree_name);
		}
        //Error if privacy level is not valid
		//elseif (!in_array($privacy, ['none', 'gedadmin', 'user', 'visitor'])) {
		//	$response = $this->showErrorMessage(I18N::translate('Privacy level not accepted') . ': ' . $privacy);
        //} 	

		//If no errors, start the core activities of the module
		else {
			$response = $this->render();
		}
		return $response;		
    }
	
	/**
	 * lastname
	 * placeName
	 * placeId - GOV-Kennung des Ortes
	 * since - (optional) liefere nur Einträge, die jünger als das angegebene Datum sind. Datum in der Form yyyy-mm-dd
	 * searchResult - Trefferliste
	 *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */	
    public function render(): ResponseInterface
	{
		// diese Such-Parameter aus der aufrufenden URL holen und hier als Parameter übergeben
		$lastname  = 'Nachname';
		$placename = 'Ort';
		$placeId   = 'GOVtest1234';
		$since 	   = '2023-11-01';
		
		// tbd: Content-Type muss auf 'text/xml' gesetzt werden
		// statt den Suchparametern hier die Trefferliste übergeben
		return $this->viewResponse($this->name() . '::json', [
            'title'        	=> 'JSON',
			'tree'			=> null,
			'module_name'	=> $this->title(),
			'lastname'      => $lastname,
			'placename'		=> $placename,
			'placeId'  	   	=> $placeId,
			'since'			=> $since,
		]);		
    }
}