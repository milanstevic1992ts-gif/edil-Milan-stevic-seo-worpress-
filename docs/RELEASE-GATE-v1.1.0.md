# Release gate v1.1.0

Branch tecnica per eseguire la CI completa sulla sorgente v1.1.0 già presente in main.

Il runtime del plugin deve rimanere identico a main. Questa branch aggiunge soltanto questa nota in docs.

La release viene pubblicata solo se:
- EMS Local SEO CI termina con successo;
- upgrade dall'ultima tag stabile e rollback passano;
- i file runtime testati coincidono con main;
- ZIP e sintassi vengono verificati.
