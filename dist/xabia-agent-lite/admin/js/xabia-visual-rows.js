/**
 * Filas visuales: expansiones léxicas y mapeador de relaciones multi-fuente.
 */
(function () {
  'use strict';

  var I18N = {
    chooseOrigin: '— Elegir origen primero —',
    loading: '— Cargando… —',
    chooseMeta: 'Escribe o selecciona la clave...',
    chooseOriginHint: 'Selecciona el origen para cargar sugerencias o escribe la clave manualmente.',
    metaFallback: 'No se pudieron cargar metas desde la fuente. Escribe la clave manualmente.',
    networkError: 'Error de red al cargar campos meta.'
  };

  function reindexNames(list, attr, prefix) {
    var rows = list.querySelectorAll('[' + attr + ']');
    rows.forEach(function (row, i) {
      row.querySelectorAll('[name]').forEach(function (el) {
        var name = el.getAttribute('name') || '';
        el.setAttribute('name', name.replace(new RegExp('^' + prefix + '\\[\\d+\\]'), prefix + '[' + i + ']'));
      });
    });
  }

  function entityOptionHtml(slug, label, kinds) {
    var kind = (kinds && kinds[slug]) ? String(kinds[slug]) : 'content';
    return '<option value="' + String(slug).replace(/"/g, '&quot;') + '" data-kind="' +
      kind.replace(/"/g, '&quot;') + '">' + String(label).replace(/</g, '&lt;') + '</option>';
  }

  function fillEntitySelects(root, entities, kinds) {
    var optionsHtml = '<option value="">— Elegir —</option>';
    Object.keys(entities || {}).forEach(function (slug) {
      optionsHtml += entityOptionHtml(slug, entities[slug] || slug, kinds);
    });
    root.querySelectorAll('.xabia-rel-entity-select').forEach(function (sel) {
      var current = sel.value;
      sel.innerHTML = optionsHtml;
      if (current) {
        sel.value = current;
      }
    });
    var tpl = document.getElementById('xabia-rel-row-tpl');
    if (tpl && tpl.content) {
      tpl.content.querySelectorAll('.xabia-rel-entity-select').forEach(function (sel) {
        sel.innerHTML = optionsHtml;
      });
    }
  }

  function formSourcePayload() {
    var form = document.getElementById('xabia-project-form');
    if (!form) return {};
    var fd = new FormData(form);
    return {
      project_id: fd.get('project_id') || '',
      source_type: fd.get('source_type') || '',
      addon_slug: fd.get('addon_slug') || '',
      sql_host: fd.get('sql_host') || '',
      sql_user: fd.get('sql_user') || '',
      sql_name: fd.get('sql_name') || '',
      sql_pass: fd.get('sql_pass') || '',
      sql_prefix: fd.get('sql_prefix') || '',
      sql_query: fd.get('sql_query') || ''
    };
  }

  function ajaxNonce() {
    var form = document.getElementById('xabia-project-form');
    if (window.xabiaVisualRows && window.xabiaVisualRows.nonce) {
      return window.xabiaVisualRows.nonce;
    }
    if (form && form.getAttribute('data-xabia-nonce')) {
      return form.getAttribute('data-xabia-nonce');
    }
    return '';
  }

  function storeNonce(nonce) {
    if (!nonce) return;
    if (window.xabiaVisualRows) {
      window.xabiaVisualRows.nonce = nonce;
    }
    var form = document.getElementById('xabia-project-form');
    if (form) {
      form.setAttribute('data-xabia-nonce', nonce);
    }
  }

  function postAjax(extraPayload) {
    var payload = formSourcePayload();
    Object.keys(extraPayload || {}).forEach(function (k) {
      payload[k] = extraPayload[k];
    });
    payload.nonce = payload.nonce || ajaxNonce();

    var body = new URLSearchParams();
    Object.keys(payload).forEach(function (k) {
      body.append(k, payload[k] == null ? '' : String(payload[k]));
    });

    return fetch((window.xabiaVisualRows && window.xabiaVisualRows.ajaxUrl) || window.ajaxurl || '/wp-admin/admin-ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }

  function metaFieldParts(row) {
    if (!row) return null;
    return {
      wrap: row.querySelector('[data-xabia-meta-key-field]'),
      input: row.querySelector('[data-xabia-meta-key-input]'),
      picker: row.querySelector('[data-xabia-meta-key-picker]'),
      datalist: row.querySelector('[data-xabia-meta-key-list]'),
      hint: row.querySelector('[data-xabia-meta-key-hint]')
    };
  }

  function ensureMetaDatalistId(parts) {
    if (!parts || !parts.input || !parts.datalist) return;
    if (!parts.datalist.id) {
      parts.datalist.id = 'xabia-meta-keys-' + Math.random().toString(36).slice(2, 10);
    }
    parts.input.setAttribute('list', parts.datalist.id);
  }

  function showMetaKeyFallback(parts, message, detail) {
    if (!parts || !parts.hint) return;
    parts.hint.style.display = '';
    var text = message || I18N.metaFallback;
    if (detail) {
      text += ' ' + detail;
    }
    parts.hint.textContent = text;
    if (parts.picker) {
      parts.picker.innerHTML = '<option value="">— Sugerencias detectadas —</option>';
    }
  }

  function sourceKindFromSelect(sourceSel) {
    if (!sourceSel) return 'content';
    var opt = sourceSel.selectedOptions && sourceSel.selectedOptions[0];
    if (!opt) {
      opt = sourceSel.options[sourceSel.selectedIndex];
    }
    if (!opt) return 'content';
    return String(opt.getAttribute('data-kind') || 'content');
  }

  function fillMetaKeySuggestions(parts, keys, relationKeys, debug, acfRecommended) {
    if (!parts) return;
    keys = keys || [];
    relationKeys = relationKeys || [];
    acfRecommended = acfRecommended || [];

    var acfMap = {};
    acfRecommended.forEach(function (item) {
      if (!item) return;
      var k = String(item.key || item || '');
      if (!k) return;
      acfMap[k] = String(item.label || (k + ' (ACF)'));
    });
    relationKeys.forEach(function (k) {
      k = String(k);
      if (k && !acfMap[k]) {
        acfMap[k] = k + ' (ACF)';
      }
    });

    var acf = [];
    var rest = [];
    var seen = {};
    Object.keys(acfMap).forEach(function (k) {
      acf.push(k);
      seen[k] = true;
    });
    keys.forEach(function (key) {
      var k = String(key);
      if (seen[k]) return;
      rest.push(k);
      seen[k] = true;
    });
    acf.sort();
    rest.sort();

    if (parts.datalist) {
      var dl = '';
      acf.forEach(function (k) {
        dl += '<option value="' + k.replace(/"/g, '&quot;') + '">' +
          String(acfMap[k] || k).replace(/</g, '&lt;') + '</option>';
      });
      rest.forEach(function (k) {
        dl += '<option value="' + k.replace(/"/g, '&quot;') + '"></option>';
      });
      parts.datalist.innerHTML = dl;
    }

    if (parts.picker) {
      var html = '<option value="">— Sugerencias detectadas (' + (acf.length + rest.length) + ') —</option>';
      if (acf.length) {
        html += '<optgroup label="Campos ACF (autodetectados)">';
        acf.forEach(function (k) {
          html += '<option value="' + k.replace(/"/g, '&quot;') + '">' +
            String(acfMap[k] || k).replace(/</g, '&lt;') + '</option>';
        });
        html += '</optgroup>';
      }
      if (rest.length) {
        html += '<optgroup label="Otras metas (postmeta)">';
        rest.slice(0, 200).forEach(function (k) {
          html += '<option value="' + k.replace(/"/g, '&quot;') + '">' + k.replace(/</g, '&lt;') + '</option>';
        });
        html += '</optgroup>';
      }
      parts.picker.innerHTML = html;
      if (parts.input && parts.input.value) {
        parts.picker.value = parts.input.value;
      }
    }

    if (parts.input) {
      parts.input.setAttribute('placeholder', I18N.chooseMeta);
    }
    if (parts.hint) {
      var msg = 'ACF autodetectados: ' + acf.length + ' · otras metas: ' + rest.length;
      if (debug && debug.sql_error) {
        msg += ' · aviso SQL: ' + debug.sql_error;
      } else if (acf.length === 0) {
        msg += '. Puedes escribir la clave meta manualmente si no aparece en la lista.';
      }
      parts.hint.style.display = '';
      parts.hint.textContent = msg;
    }
  }

  function resetMetaKeySuggestions(parts) {
    if (!parts) return;
    ensureMetaDatalistId(parts);
    if (parts.datalist) {
      parts.datalist.innerHTML = '';
    }
    if (parts.picker) {
      parts.picker.innerHTML = '<option value="">— Sugerencias detectadas —</option>';
    }
    if (parts.input) {
      parts.input.setAttribute('placeholder', I18N.chooseMeta);
    }
    if (parts.hint) {
      parts.hint.style.display = '';
      parts.hint.textContent = I18N.chooseOriginHint;
    }
  }

  function loadMetaKeysForRow(row) {
    if (!row) return;
    var sourceSel = row.querySelector('select[name*="[source_post_type]"]');
    var parts = metaFieldParts(row);
    if (!sourceSel || !parts) return;

    var postType = String(sourceSel.value || '').trim();
    ensureMetaDatalistId(parts);
    if (postType === '') {
      resetMetaKeySuggestions(parts);
      return;
    }

    var current = parts.input ? String(parts.input.value || '') : '';
    if (parts.input) {
      parts.input.setAttribute('placeholder', I18N.loading);
    }
    if (parts.picker) {
      parts.picker.innerHTML = '<option value="">' + I18N.loading + '</option>';
    }
    if (parts.hint) {
      parts.hint.style.display = 'none';
    }

    postAjax({
      action: 'xabia_relation_meta_keys',
      source_post_type: postType,
      source_kind: sourceKindFromSelect(sourceSel)
    }).then(function (json) {
      if (json && json.data && json.data.nonce) {
        storeNonce(json.data.nonce);
      }
      if (!json || !json.success || !json.data) {
        showMetaKeyFallback(parts, I18N.networkError, '');
        return;
      }
      var data = json.data;
      if (data.fallback || !data.ok || !data.meta_keys || !data.meta_keys.length) {
        showMetaKeyFallback(parts, data.message || I18N.metaFallback, data.error_detail || '');
        return;
      }
      fillMetaKeySuggestions(
        parts,
        data.meta_keys,
        data.relation_meta_keys || [],
        data.debug || null,
        data.acf_recommended || []
      );
      if (parts.input && current) {
        parts.input.value = current;
        if (parts.picker) {
          parts.picker.value = current;
        }
      }
    }).catch(function () {
      showMetaKeyFallback(parts, I18N.networkError, '');
    });
  }

  function initMetaKeysInRoot(root) {
    root.querySelectorAll('[data-xabia-rel-row]').forEach(function (row) {
      var sourceSel = row.querySelector('select[name*="[source_post_type]"]');
      if (sourceSel && sourceSel.value) {
        loadMetaKeysForRow(row);
      }
    });
  }

  function bind(root) {
    root = root || document;

    root.querySelectorAll('[data-xabia-add-kw]').forEach(function (btn) {
      if (btn._xabiaBound) return;
      btn._xabiaBound = true;
      btn.addEventListener('click', function () {
        var block = btn.closest('.xabia-visual-block');
        var list = block && block.querySelector('[data-xabia-kw-list]');
        var tpl = block && block.querySelector('#xabia-kw-row-tpl');
        if (!list || !tpl) return;
        list.appendChild(tpl.content.cloneNode(true));
        reindexNames(list, 'data-xabia-kw-row', 'keyword_exp_rows');
      });
    });

    root.querySelectorAll('[data-xabia-add-rel]').forEach(function (btn) {
      if (btn._xabiaBound) return;
      btn._xabiaBound = true;
      btn.addEventListener('click', function () {
        var block = btn.closest('.xabia-visual-block');
        var list = block && block.querySelector('[data-xabia-rel-list]');
        var tpl = block && block.querySelector('#xabia-rel-row-tpl');
        if (!list || !tpl) return;
        list.appendChild(tpl.content.cloneNode(true));
        reindexNames(list, 'data-xabia-rel-row', 'knowledge_rel_rows');
      });
    });

    root.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-xabia-remove-row]');
      if (!rm) return;
      var row = rm.closest('[data-xabia-kw-row], [data-xabia-rel-row]');
      if (!row) return;
      var list = row.parentElement;
      var isKw = row.hasAttribute('data-xabia-kw-row');
      row.remove();
      if (list) {
        reindexNames(list, isKw ? 'data-xabia-kw-row' : 'data-xabia-rel-row', isKw ? 'keyword_exp_rows' : 'knowledge_rel_rows');
        if (list.children.length === 0) {
          var block = list.closest('.xabia-visual-block');
          var tpl = block && block.querySelector(isKw ? '#xabia-kw-row-tpl' : '#xabia-rel-row-tpl');
          if (tpl) {
            list.appendChild(tpl.content.cloneNode(true));
            reindexNames(list, isKw ? 'data-xabia-kw-row' : 'data-xabia-rel-row', isKw ? 'keyword_exp_rows' : 'knowledge_rel_rows');
          }
        }
      }
    });

    root.addEventListener('change', function (e) {
      var sel = e.target;
      if (!sel || !sel.matches) return;

      if (sel.matches('[data-xabia-meta-key-picker]')) {
        var rowMeta = sel.closest('[data-xabia-rel-row]');
        var partsMeta = metaFieldParts(rowMeta);
        if (partsMeta && partsMeta.input && sel.value) {
          partsMeta.input.value = sel.value;
        }
        return;
      }

      if (!sel.matches('select[name*="[source_post_type]"]')) {
        return;
      }
      loadMetaKeysForRow(sel.closest('[data-xabia-rel-row]'));
    });

    root.querySelectorAll('[data-xabia-refresh-rel-types]').forEach(function (btn) {
      if (btn._xabiaBound) return;
      btn._xabiaBound = true;
      btn.addEventListener('click', function () {
        btn.disabled = true;
        postAjax({ action: 'xabia_relation_entity_types' }).then(function (json) {
          btn.disabled = false;
          if (!json || !json.success) {
            window.alert((json && json.data && json.data.message) || 'No se pudieron cargar los tipos.');
            return;
          }
          var data = json.data || {};
          if (data.nonce) {
            storeNonce(data.nonce);
          }
          fillEntitySelects(document.getElementById('xabia-knowledge-relations-block') || document, data.entities || {}, data.kinds || {});
          initMetaKeysInRoot(document.getElementById('xabia-knowledge-relations-block') || document);
        }).catch(function () {
          btn.disabled = false;
          window.alert('Error de red al actualizar tipos.');
        });
      });
    });

    initMetaKeysInRoot(root);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { bind(document); });
  } else {
    bind(document);
  }
})();
