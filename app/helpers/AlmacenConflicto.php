<?php
/**
 * En el lugar donde se iba a escribir ya hay otro documento distinto.
 *
 * No es un fallo del almacén ni de la red: es una respuesta, y quien llama
 * sabe qué hacer con ella. Hoy el único que sabe es DocumentoArchivo, que le
 * pone al archivo el sufijo `_DUP_<8 hex del hash>` y vuelve a intentar.
 *
 * Existe como excepción y no como un valor de retorno porque el camino normal
 * —escribir y que quede escrito— no debe cargar con una comprobación en cada
 * llamada. Y existe aparte de RuntimeException porque contra SharePoint esto
 * llega como un 409 `nameAlreadyExists`, que es lo único de la familia de los
 * errores HTTP que NO hay que reintentar: reintentarlo da el mismo 409 para
 * siempre.
 *
 * Ojo con `hashExistente`: puede venir vacío. En disco cuesta una lectura y
 * siempre se sabe; contra SharePoint sale de una columna de la biblioteca, y
 * de un archivo que subió otra herramienta esa columna está vacía. Vacío
 * significa "no se sabe", nunca "son distintos".
 */

class AlmacenConflicto extends RuntimeException
{
    /** @var string */
    private $rutaOcupada;

    /** @var string */
    private $hashExistente;

    public function __construct($rutaOcupada, $hashExistente = '', $mensaje = '')
    {
        $this->rutaOcupada = (string) $rutaOcupada;
        $this->hashExistente = (string) $hashExistente;

        parent::__construct(
            $mensaje !== ''
                ? $mensaje
                : 'Ya hay otro documento distinto en ' . $this->rutaOcupada . '.'
        );
    }

    public function rutaOcupada()
    {
        return $this->rutaOcupada;
    }

    /** SHA-256 de lo que ya estaba, o '' si el almacén no lo sabe. */
    public function hashExistente()
    {
        return $this->hashExistente;
    }
}
