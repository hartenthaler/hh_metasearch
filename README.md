# metasearch
webtrees module to support the "Metasuche" at https://meta.genealogy.net/

This is an alpha version. Do not install in productive webtrees systems!

URL:
https://xxx/index.php?route=MetaSearch&tree=kennedy&key=yyy&lastname=aaa&placename=bbb&placeId=ccc&since=ddd

Parameter tree kann fehlen, dann werden alle vom Administrator definierten trees durchsucht

Parameter key kann fehlen; wenn der Administrator aber einen key definiert hat, dann muss dieser auch korrekt angegeben werden.

Alle weiteren Parameter sind optional; wenn lastname und placename und placeId fehlen, wird ein leeres Ergebnis für jeden tree zurückgegeben.

Wenn der Parameter since angegeben ist, dann werden nur Ergebnisse zurück geliefert, deren letztes Änderungsdatum (CHAN) definiert und neuer ist.

Wie sieht die URL bei Pretty-URL aus?
