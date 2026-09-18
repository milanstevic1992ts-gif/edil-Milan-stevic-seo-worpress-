# Release gate v1.0.1

Branch tecnica usata esclusivamente per eseguire la CI completa sulla sorgente plugin già presente in main.

La release workflow pubblica soltanto se:
- EMS Local SEO CI termina con successo;
- la branch è `main` oppure `release/*`;
- i file runtime del plugin sono identici a quelli presenti in `main`.

Questo file non entra nello ZIP del plugin e non modifica il runtime.
