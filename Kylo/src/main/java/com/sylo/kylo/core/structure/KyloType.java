package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;

/**
 * Clase Abstracta KyloType: La clase base para todos los tipos de datos.
 * Define métodos para obtener el tamaño fijo, validar entradas y serializar/deserializar.
 */
public abstract class KyloType {

    /**
     * Devuelve el tamaño fijo del tipo en bytes.
     * Si es longitud variable, devuelve -1 o lanza una excepción dependiendo del uso.
     */
    public abstract int getFixedSize();

    /**
     * Indica si el tipo es de longitud variable.
     */
    public abstract boolean isVariableLength();

    /**
     * Valida si el objeto de entrada es compatible con este tipo.
     */
    public abstract void validate(Object value);

    /**
     * Serializa el valor a un array de bytes.
     */
    public abstract byte[] serialize(Object value);

    /**
     * Deserializa un valor desde un array de bytes.
     */
    public abstract Object deserialize(byte[] data);
    
    /**
     * Deserializa un valor leyendo desde un ByteBuffer en la posición actual.
     */
    public abstract Object deserialize(ByteBuffer buffer);
    
    /**
     * Serializa escribiendo en un ByteBuffer.
     */
    public abstract void serialize(Object value, ByteBuffer buffer);
}
