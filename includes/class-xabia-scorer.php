<?php
/**
 * Motor de Relevancia Genérico para Xabia AI
 * Calcula la puntuación de cada registro basándose en pesos configurables.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Xabia_Scorer {

	/**
	 * Pesos por defecto (Configurables por el Admin)
	 */
	private $weights;

	public function __construct() {
		// Recuperar pesos de la configuración de WordPress o usar valores por defecto
		$this->weights = get_option( 'xabia_scoring_weights', [
			'alta'   => 10,
			'media'  => 5,
			'baja'   => 2,
			'minima' => 1
		]);
	}

	/**
	 * Calcula el score de una fila de datos.
	 * * @param array  $row      Los datos de la empresa (fila CSV o Array de Post Meta).
	 * @param string $query    La búsqueda del usuario (ej: "parapente").
	 * @param array  $mapping  Mapa de qué columna/campo tiene qué prioridad.
	 * @return int   Puntuación total.
	 */
	public function calculate_score( $row, $query, $mapping ) {
		$score = 0;
		$query = mb_strtolower( trim( $query ) );

		if ( empty( $query ) ) {
			return 0;
		}

		// El mapeo indica qué campo tiene qué prioridad (ej: 'subcategoria_01' => 'alta')
		foreach ( $mapping as $field_key => $priority ) {
			
			if ( ! isset( $row[ $field_key ] ) || empty( $row[ $field_key ] ) ) {
				continue;
			}

			$content = mb_strtolower( (string) $row[ $field_key ] );

			// 1. Coincidencia exacta (Mayor relevancia)
			if ( $content === $query ) {
				$score += ( $this->weights[ $priority ] * 2 );
			} 
			// 2. Coincidencia parcial (Contiene la palabra)
			elseif ( strpos( $content, $query ) !== false ) {
				$score += $this->weights[ $priority ];
			}
		}

		return $score;
	}

	/**
	 * Ordena y filtra los resultados para enviar solo los más relevantes a la IA.
	 */
	public function get_top_results( $results, $limit = 5 ) {
		usort( $results, function( $a, $b ) {
			return $b['xabia_score'] <=> $a['xabia_score'];
		});

		// Filtrar resultados con score 0 para evitar que la IA se invente respuestas
		$filtered = array_filter( $results, function( $res ) {
			return $res['xabia_score'] > 0;
		});

		return array_slice( $filtered, 0, $limit );
	}
}