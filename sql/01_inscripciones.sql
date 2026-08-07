-- ===========================================================================
-- Inscripciones al evento del Día del Niño
--
-- Una fila por grupo familiar. El titular se identifica con su DNI contra
-- `padron`, así que el documento alcanza como clave: se guarda con UNIQUE
-- para que nadie se anote dos veces por error.
--
-- Los datos del padrón (nombre, empresa) se copian acá a propósito. El
-- padrón se reemplaza mes a mes y la inscripción tiene que conservar lo
-- que la persona declaró el día que se anotó.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS inscripciones_dia_del_nino (
  id                 INT NOT NULL AUTO_INCREMENT,
  nro_doc            INT          NOT NULL COMMENT 'DNI del titular, validado contra padron',
  cuil_titular       CHAR(11)     NULL     COMMENT 'Grupo familiar al que pertenece',
  nombre_titular     VARCHAR(60)  NOT NULL,
  empresa            VARCHAR(60)  NULL,
  telefono           VARCHAR(40)  NULL,
  email              VARCHAR(80)  NULL,
  cantidad_adultos   TINYINT UNSIGNED NOT NULL,
  cantidad_ninos     TINYINT UNSIGNED NOT NULL,
  ninos_habilitados  TINYINT UNSIGNED NOT NULL
                     COMMENT 'Cuantos menores de la edad tope tenia en el padron al inscribirse',
  creado             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,
  ip                 VARCHAR(45)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inscripcion_doc (nro_doc),
  KEY idx_inscripcion_empresa (empresa),
  KEY idx_inscripcion_creado (creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='Inscripciones al evento del Dia del Nino. Una por grupo familiar.';
