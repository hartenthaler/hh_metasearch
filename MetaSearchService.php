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

use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * MetaSearchService
 */
class MetaSearchService
{
    public const ACCESS_LEVELS = [
        'gedadmin' => Auth::PRIV_NONE,
        'user'     => Auth::PRIV_USER,
        'visitor'  => Auth::PRIV_PRIVATE,
        'none'     => Auth::PRIV_HIDE,
    ];

    private ResponseFactoryInterface $response_factory;
    
    /**
     * @param ResponseFactoryInterface $response_factory
     */
	public function __construct(ResponseFactoryInterface $response_factory)
	{
		$this->response_factory = $response_factory;
	}
	
	//tbd Funktionen um die Parameter aus der URL zu lesen
	//tbd Wenn lastname und placename und gov alle drei nicht angegeben oder leer sind: leeres JSON-Gerüst zurückgeben
	//tbd Mit den und-verknüpften Parametern in allen Bäumen suchen und Trefferliste erstellen (bis jeweils max_hit+1)
	//tbd Für alle Trees: Ausgabe des Datenbanknamens (mit Tree-Bezeichnung) und der URL in JSON (Funktionen dafür definieren)
	//tbd Zu jedem einzelnen Tree maximal max_hit Treffer (jeweils Funktionen dafür definieren):
	//		- Vollständiger höchstpriorer Name mit 1. Nachnamen (mit Pre+Postfix) und 2. Vornamen (inkl. Spitz- und Rufname)
	//		- Details: * Geburtsjahr Geburtsort (PLAC) + Todesjahr und Todesort (PLAC)
	//		- URL zum Treffer (enthält Hinweis auf den Tree)
	//		- ggf. Kennzeichen "more"
	//tbd JSON statt Text
	
}
