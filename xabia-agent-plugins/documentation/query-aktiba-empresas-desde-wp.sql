-- Query para Xabia: mismos campos que el CSV de Aktiba (origen: WP + ACF post type "empresa")
-- Sustituye 4ygrzUK_ por el prefijo de tu BD (ej. wp_)
-- En Xabia Admin: Fuente SQL remota → Host, Usuario, Contraseña, Nombre BD; pega esta query.
--
-- Categorías: taxonomía categoria-de-actividad (save_terms = 1 → wp_term_relationships).
-- Experiencias: ACF relationship experiencias_relacionadas (post type experiencia), valor serializado en postmeta.

SELECT
  p.ID,
  COALESCE(MAX(CASE WHEN m.meta_key = 'empresa' THEN m.meta_value END), p.post_title) AS empresa,
  p.post_name AS Slug_Empresa,
  CONCAT('https://aktiba.eus/empresa/', p.post_name, '/') AS URL_Empresa,
  COALESCE(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 1), NULL) AS categoria,
  COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 2), CHAR(124,124,124), -1), SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 1)), NULL) AS subcategoria_01,
  COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 3), CHAR(124,124,124), -1), SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 2), CHAR(124,124,124), -1)), NULL) AS subcategoria_02,
  COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 4), CHAR(124,124,124), -1), SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 3), CHAR(124,124,124), -1)), NULL) AS subcategoria_03,
  COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 5), CHAR(124,124,124), -1), SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 4), CHAR(124,124,124), -1)), NULL) AS subcategoria_04,
  COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 6), CHAR(124,124,124), -1), SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 5), CHAR(124,124,124), -1)), NULL) AS subcategoria_05,
  COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 7), CHAR(124,124,124), -1), SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 6), CHAR(124,124,124), -1)), NULL) AS subcategoria_06,
  COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 8), CHAR(124,124,124), -1), SUBSTRING_INDEX(SUBSTRING_INDEX(cats.cat_concat, CHAR(124,124,124), 7), CHAR(124,124,124), -1)), NULL) AS subcategoria_07,
  (SELECT exp.post_title FROM 4ygrzUK_postmeta pm INNER JOIN 4ygrzUK_posts exp ON exp.ID = CAST(TRIM(BOTH CHAR(34) FROM SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, CHAR(34,59), 1), CHAR(34), -1)) AS UNSIGNED) AND exp.post_type = 'experiencia' AND exp.post_status = 'publish' WHERE pm.post_id = p.ID AND pm.meta_key = 'experiencias_relacionadas' AND LENGTH(TRIM(pm.meta_value)) > 0 LIMIT 1) AS experiencia_01,
  (SELECT exp.post_title FROM 4ygrzUK_postmeta pm INNER JOIN 4ygrzUK_posts exp ON exp.ID = CAST(TRIM(BOTH CHAR(34) FROM SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, CHAR(34,59), 2), CHAR(34), -1)) AS UNSIGNED) AND exp.post_type = 'experiencia' AND exp.post_status = 'publish' WHERE pm.post_id = p.ID AND pm.meta_key = 'experiencias_relacionadas' AND (LENGTH(pm.meta_value) - LENGTH(REPLACE(pm.meta_value, CHAR(34,59), ''))) >= 2 LIMIT 1) AS experiencia_02,
  (SELECT exp.post_title FROM 4ygrzUK_postmeta pm INNER JOIN 4ygrzUK_posts exp ON exp.ID = CAST(TRIM(BOTH CHAR(34) FROM SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, CHAR(34,59), 3), CHAR(34), -1)) AS UNSIGNED) AND exp.post_type = 'experiencia' AND exp.post_status = 'publish' WHERE pm.post_id = p.ID AND pm.meta_key = 'experiencias_relacionadas' AND (LENGTH(pm.meta_value) - LENGTH(REPLACE(pm.meta_value, CHAR(34,59), ''))) >= 3 LIMIT 1) AS experiencia_03,
  (SELECT exp.post_title FROM 4ygrzUK_postmeta pm INNER JOIN 4ygrzUK_posts exp ON exp.ID = CAST(TRIM(BOTH CHAR(34) FROM SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, CHAR(34,59), 4), CHAR(34), -1)) AS UNSIGNED) AND exp.post_type = 'experiencia' AND exp.post_status = 'publish' WHERE pm.post_id = p.ID AND pm.meta_key = 'experiencias_relacionadas' AND (LENGTH(pm.meta_value) - LENGTH(REPLACE(pm.meta_value, CHAR(34,59), ''))) >= 4 LIMIT 1) AS experiencia_04,
  (SELECT exp.post_title FROM 4ygrzUK_postmeta pm INNER JOIN 4ygrzUK_posts exp ON exp.ID = CAST(TRIM(BOTH CHAR(34) FROM SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, CHAR(34,59), 5), CHAR(34), -1)) AS UNSIGNED) AND exp.post_type = 'experiencia' AND exp.post_status = 'publish' WHERE pm.post_id = p.ID AND pm.meta_key = 'experiencias_relacionadas' AND (LENGTH(pm.meta_value) - LENGTH(REPLACE(pm.meta_value, CHAR(34,59), ''))) >= 5 LIMIT 1) AS experiencia_05,
  (SELECT exp.post_title FROM 4ygrzUK_postmeta pm INNER JOIN 4ygrzUK_posts exp ON exp.ID = CAST(TRIM(BOTH CHAR(34) FROM SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, CHAR(34,59), 6), CHAR(34), -1)) AS UNSIGNED) AND exp.post_type = 'experiencia' AND exp.post_status = 'publish' WHERE pm.post_id = p.ID AND pm.meta_key = 'experiencias_relacionadas' AND (LENGTH(pm.meta_value) - LENGTH(REPLACE(pm.meta_value, CHAR(34,59), ''))) >= 6 LIMIT 1) AS experiencia_06,
  (SELECT exp.post_title FROM 4ygrzUK_postmeta pm INNER JOIN 4ygrzUK_posts exp ON exp.ID = CAST(TRIM(BOTH CHAR(34) FROM SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, CHAR(34,59), 7), CHAR(34), -1)) AS UNSIGNED) AND exp.post_type = 'experiencia' AND exp.post_status = 'publish' WHERE pm.post_id = p.ID AND pm.meta_key = 'experiencias_relacionadas' AND (LENGTH(pm.meta_value) - LENGTH(REPLACE(pm.meta_value, CHAR(34,59), ''))) >= 7 LIMIT 1) AS experiencia_07,
  (SELECT exp.post_title FROM 4ygrzUK_postmeta pm INNER JOIN 4ygrzUK_posts exp ON exp.ID = CAST(TRIM(BOTH CHAR(34) FROM SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, CHAR(34,59), 8), CHAR(34), -1)) AS UNSIGNED) AND exp.post_type = 'experiencia' AND exp.post_status = 'publish' WHERE pm.post_id = p.ID AND pm.meta_key = 'experiencias_relacionadas' AND (LENGTH(pm.meta_value) - LENGTH(REPLACE(pm.meta_value, CHAR(34,59), ''))) >= 8 LIMIT 1) AS experiencia_08,
  (SELECT exp.post_title FROM 4ygrzUK_postmeta pm INNER JOIN 4ygrzUK_posts exp ON exp.ID = CAST(TRIM(BOTH CHAR(34) FROM SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, CHAR(34,59), 9), CHAR(34), -1)) AS UNSIGNED) AND exp.post_type = 'experiencia' AND exp.post_status = 'publish' WHERE pm.post_id = p.ID AND pm.meta_key = 'experiencias_relacionadas' AND (LENGTH(pm.meta_value) - LENGTH(REPLACE(pm.meta_value, CHAR(34,59), ''))) >= 9 LIMIT 1) AS experiencia_09,
  (SELECT exp.post_title FROM 4ygrzUK_postmeta pm INNER JOIN 4ygrzUK_posts exp ON exp.ID = CAST(TRIM(BOTH CHAR(34) FROM SUBSTRING_INDEX(SUBSTRING_INDEX(pm.meta_value, CHAR(34,59), 10), CHAR(34), -1)) AS UNSIGNED) AND exp.post_type = 'experiencia' AND exp.post_status = 'publish' WHERE pm.post_id = p.ID AND pm.meta_key = 'experiencias_relacionadas' AND (LENGTH(pm.meta_value) - LENGTH(REPLACE(pm.meta_value, CHAR(34,59), ''))) >= 10 LIMIT 1) AS experiencia_10,
  MAX(CASE WHEN m.meta_key = 'empresa_tel' THEN m.meta_value END) AS empresa_tel,
  MAX(CASE WHEN m.meta_key = 'empresa_email' THEN m.meta_value END) AS empresa_email,
  MAX(CASE WHEN m.meta_key = 'empresa_web' THEN m.meta_value END) AS empresa_web,
  MAX(CASE WHEN m.meta_key = 'empresa_reservas' THEN m.meta_value END) AS empresa_reservas,
  MAX(CASE WHEN m.meta_key = 'empresa_localizacion' THEN m.meta_value END) AS empresa_localizacion,
  MAX(CASE WHEN m.meta_key = 'empresa_logo' THEN m.meta_value END) AS `@empresa_logo`,
  MAX(CASE WHEN m.meta_key = 'empresa_img_01' THEN m.meta_value END) AS `@empresa_img_01`,
  MAX(CASE WHEN m.meta_key = 'empresa_img_02' THEN m.meta_value END) AS `@empresa_img_02`,
  MAX(CASE WHEN m.meta_key = 'empresa_img_03' THEN m.meta_value END) AS `@empresa_img_03`,
  MAX(CASE WHEN m.meta_key = 'empresa_img_04' THEN m.meta_value END) AS `@empresa_img_04`,
  MAX(CASE WHEN m.meta_key = 'eslogan_empresa' THEN m.meta_value END) AS eslogan_empresa,
  MAX(CASE WHEN m.meta_key = 'descripcion_empresa' THEN m.meta_value END) AS descripcion_empresa,
  MAX(CASE WHEN m.meta_key = 'cliente_01' THEN m.meta_value END) AS cliente_01,
  MAX(CASE WHEN m.meta_key = 'cliente_02' THEN m.meta_value END) AS cliente_02,
  MAX(CASE WHEN m.meta_key = 'cliente_03' THEN m.meta_value END) AS cliente_03,
  MAX(CASE WHEN m.meta_key = 'testimonio_01' THEN m.meta_value END) AS testimonio_01,
  MAX(CASE WHEN m.meta_key = 'testimonio_02' THEN m.meta_value END) AS testimonio_02,
  MAX(CASE WHEN m.meta_key = 'testimonio_03' THEN m.meta_value END) AS testimonio_03,
  MAX(CASE WHEN m.meta_key = 'faq_01' THEN m.meta_value END) AS faq_01,
  MAX(CASE WHEN m.meta_key = 'respuesta_faq_01' THEN m.meta_value END) AS respuesta_faq_01,
  MAX(CASE WHEN m.meta_key = 'faq_02' THEN m.meta_value END) AS faq_02,
  MAX(CASE WHEN m.meta_key = 'respuesta_faq_02' THEN m.meta_value END) AS respuesta_faq_02,
  MAX(CASE WHEN m.meta_key = 'faq_03' THEN m.meta_value END) AS faq_03,
  MAX(CASE WHEN m.meta_key = 'respuesta_faq_03' THEN m.meta_value END) AS respuesta_faq_03,
  MAX(CASE WHEN m.meta_key = 'faq_04' THEN m.meta_value END) AS faq_04,
  MAX(CASE WHEN m.meta_key = 'respuesta_faq_04' THEN m.meta_value END) AS respuesta_faq_04,
  MAX(CASE WHEN m.meta_key = 'faq_05' THEN m.meta_value END) AS faq_05,
  MAX(CASE WHEN m.meta_key = 'respuesta_faq_05' THEN m.meta_value END) AS respuesta_faq_05,
  MAX(CASE WHEN m.meta_key = 'beneficio_01' THEN m.meta_value END) AS beneficio_01,
  MAX(CASE WHEN m.meta_key = 'beneficio_02' THEN m.meta_value END) AS beneficio_02,
  MAX(CASE WHEN m.meta_key = 'beneficio_03' THEN m.meta_value END) AS beneficio_03,
  MAX(CASE WHEN m.meta_key = 'beneficio_04' THEN m.meta_value END) AS beneficio_04,
  MAX(CASE WHEN m.meta_key = 'propuesta_01' THEN m.meta_value END) AS propuesta_01,
  MAX(CASE WHEN m.meta_key = 'descripcion_propuesta_01' THEN m.meta_value END) AS descripcion_propuesta_01,
  MAX(CASE WHEN m.meta_key = 'propuesta_02' THEN m.meta_value END) AS propuesta_02,
  MAX(CASE WHEN m.meta_key = 'descripcion_propuesta_02' THEN m.meta_value END) AS descripcion_propuesta_02,
  MAX(CASE WHEN m.meta_key = 'propuesta_03' THEN m.meta_value END) AS propuesta_03,
  MAX(CASE WHEN m.meta_key = 'descripcion_propuesta_03' THEN m.meta_value END) AS descripcion_propuesta_03,
  MAX(CASE WHEN m.meta_key = 'propuesta_04' THEN m.meta_value END) AS propuesta_04,
  MAX(CASE WHEN m.meta_key = 'descripcion_propuesta_04' THEN m.meta_value END) AS descripcion_propuesta_04,
  MAX(CASE WHEN m.meta_key = 'propuesta_05' THEN m.meta_value END) AS propuesta_05,
  MAX(CASE WHEN m.meta_key = 'descripcion_propuesta_05' THEN m.meta_value END) AS descripcion_propuesta_05,
  MAX(CASE WHEN m.meta_key = 'propuesta_06' THEN m.meta_value END) AS propuesta_06,
  MAX(CASE WHEN m.meta_key = 'descripcion_propuesta_06' THEN m.meta_value END) AS descripcion_propuesta_06,
  MAX(CASE WHEN m.meta_key = 'propuesta_07' THEN m.meta_value END) AS propuesta_07,
  MAX(CASE WHEN m.meta_key = 'descripcion_propuesta_07' THEN m.meta_value END) AS descripcion_propuesta_07,
  MAX(CASE WHEN m.meta_key = 'propuesta_08' THEN m.meta_value END) AS propuesta_08,
  MAX(CASE WHEN m.meta_key = 'descripcion_propuesta_08' THEN m.meta_value END) AS descripcion_propuesta_08,
  MAX(CASE WHEN m.meta_key = 'propuesta_09' THEN m.meta_value END) AS propuesta_09,
  MAX(CASE WHEN m.meta_key = 'descripcion_propuesta_09' THEN m.meta_value END) AS descripcion_propuesta_09
FROM 4ygrzUK_posts p
LEFT JOIN 4ygrzUK_postmeta m ON m.post_id = p.ID
LEFT JOIN (
  SELECT tr.object_id,
    GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR 0x7C7C7C) AS cat_concat
  FROM 4ygrzUK_term_relationships tr
  JOIN 4ygrzUK_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'categoria-de-actividad'
  JOIN 4ygrzUK_terms t ON t.term_id = tt.term_id
  GROUP BY tr.object_id
) cats ON cats.object_id = p.ID
WHERE p.post_type = 'empresa' AND p.post_status = 'publish'
GROUP BY p.ID, p.post_title, p.post_name, cats.cat_concat
ORDER BY p.post_title;

-- Nota experiencias: ACF guarda el relationship como array PHP serializado (ej. a:2:{i:0;s:3:"123";i:1;s:3:"456";}).
-- La query extrae el ID de cada posición ("; como separador) y hace JOIN a wp_posts (post_type = experiencia).
-- Si tu versión de ACF guardara IDs en otro formato, habría que ajustar la extracción.
