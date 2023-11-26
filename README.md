# metasearch
webtrees module to support the "Metasuche" at https://meta.genealogy.net/

This is an alpha version. Do not install in productive webtrees systems!

URL:
https://xxx/index.php?route=MetaSearch&tree=ttt&key=kkk&lastname=aaa&placename=bbb&placeId=ccc&since=yyyy-mm-dd

Parameter tree kann fehlen, dann werden alle vom Administrator definierten trees durchsucht.

Parameter key kann fehlen; wenn der Administrator aber einen key definiert hat, dann muss dieser auch korrekt angegeben werden.

Alle weiteren Parameter sind optional; wenn lastname und placename und placeId fehlen (also alle drei Parameter), wird ein leeres Ergebnis für jeden tree zurückgegeben. Diese drei Parmeter sind mit "und" verknüpft,

Wenn der Parameter since in der Form yyyy-mm-dd angegeben ist, dann werden nur Ergebnisse zurück geliefert, deren letztes Änderungsdatum (CHAN) definiert und neuer ist.

Wie sieht die URL bei Pretty-URL aus?
