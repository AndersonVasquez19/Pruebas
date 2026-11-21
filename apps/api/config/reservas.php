<?php

return [
    /*
     | Duración de la reserva de cupo mientras la persona paga. Nunca baja de 2 días (decisión del propietario, 21/11/2026):
     | se puede alargar con RESERVA_DIAS, pero un valor menor se ignora.
     */
    'dias' => max(2, (int) env('RESERVA_DIAS', 2)),
];
