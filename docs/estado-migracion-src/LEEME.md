# Como se regenera `docs/estado-migracion-ui-v2.html`

La pagina no se edita a mano: se genera. Las 106 funcionalidades salen de
`inventario.json` (extraido de la seccion 1.2 de `plan de mejoras.md`); el
resto de los datos —QA, pendiente, calendario, decisiones— esta en las listas
de la cabecera de `genera.py`.

```bash
python genera.py                         # escribe estado-migracion.html al lado
python acentos.py estado-migracion.html  # pone las tildes de la prosa
```

`acentos.py` existe porque el generador se escribio sin tildes. Sustituye
palabra completa solo en los nodos de texto, nunca en una clase, un atributo
ni una propiedad CSS, y las formas ambiguas (`esta`/`esta`, `paso`/`paso`,
`via`/`via`) se tocan por frase exacta, no por palabra.

Al cambiar datos, acordarse de las cifras de la banda superior (`RESUMEN`) y
del pie: no se calculan solas.
