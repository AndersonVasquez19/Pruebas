<?php

return [
    /*
     | Datos de cobro que ve la persona en la página de pago (/pagar/{enlace}). Son datos personales del titular de la cuenta, por eso
     | viven solo en el entorno (.env) y nunca en archivos versionados. Vacío = ese medio no se muestra.
     |
     | Cuenta bancaria / CCI: todavía no hay (decisión del propietario, 21/11/2026); se añade aquí cuando exista.
     */
    'yape' => [
        'numero' => (string) env('PAGOS_YAPE_NUMERO', ''),
        'titular' => (string) env('PAGOS_YAPE_TITULAR', ''),
    ],
];
