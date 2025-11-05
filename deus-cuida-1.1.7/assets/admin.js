
jQuery(function($){
  // ===== Remoção com confirmação =====
  $(document).on('click', 'a.pm-delete', function(e){
    var msg = (window.PM_CONFIG && PM_CONFIG.confirm_remove) ? PM_CONFIG.confirm_remove : 'Tem certeza que deseja remover?';
    if(!confirm(msg)) { e.preventDefault(); return false; }
  });

  // ===== DataTables (se existir) =====
  if ($('#pm-table').length){
    try{
      if($.fn.DataTable && $.fn.DataTable.isDataTable('#pm-table')) $('#pm-table').DataTable().clear().destroy();
      if ($.fn.DataTable){
        $('#pm-table').DataTable({
          pageLength: 20,
          order: [],
          language: { url: (window.PM_CONFIG ? PM_CONFIG.i18n_url : '') },
          dom: 'Bfrtip',
          buttons: [
            { extend: 'csvHtml5',   title: (PM_CONFIG?PM_CONFIG.export_filename:'export'), exportOptions: { columns: ':visible' } },
            { extend: 'excelHtml5', title: (PM_CONFIG?PM_CONFIG.export_filename:'export'), exportOptions: { columns: ':visible' } },
            { extend: 'print',      title: 'Lista',                         exportOptions: { columns: ':visible' } }
          ]
        });
      }
    }catch(err){ if(console && console.warn) console.warn('DataTable init:', err); }
  }

  // ===== Helpers =====
  function ajaxData(base){ base = base || {}; if (window.PM_CONFIG && PM_CONFIG.nonce){ base.nonce = PM_CONFIG.nonce; } return base; }

  // ===== Select2 universal =====
  function initSelect2($el){
    if(!$el.length || !$.fn.select2) return;
    var src  = $el.data('source')||'';
    var base = { width:'resolve', allowClear:true, minimumInputLength:0, dropdownParent:$(document.body) };

    if(src==='membros'){
      base.placeholder='Selecione um membro';
      base.ajax = {
        url: PM_CONFIG.ajax_url,
        type:'GET', dataType:'json', delay:200, cache:true,
        data: function(params){ return ajaxData({ action:'pm_search_membros', q:(params.term||'') }); },
        processResults: function(data){ return { results: (Array.isArray(data)?data:[]) }; }
      };
    } else if (src==='local-membros'){
      base.placeholder='Selecione um membro';
    } else if (src==='recantos'){
      base.placeholder='Selecione um recanto';
    }

    $el.select2(base);

    // Ao abrir, garante busca ativa
    $el.on('select2:open', function(){ var $i=$('.select2-container--open .select2-search__field'); if($i.length){ $i.val(''); $i.trigger('input'); } });

    // Prefetch inicial (apenas quando ajax de membros estiver ativo)
    if (src==='membros'){
      $el.one('focus', function(){
        if ($el.find('option').length <= 1){
          $.getJSON(PM_CONFIG.ajax_url, ajaxData({action:'pm_search_membros', q:''}))
            .done(function(list){ if(Array.isArray(list)){ list.slice(0,100).forEach(function(it){ $el.append(new Option(it.text, it.id, false, false)); }); $el.trigger('change.select2'); } })
            .fail(function(xhr){ if(window.console){ console.error('prefetch membros:', xhr.status, xhr.responseText); } });
        }
      });
    }
  }

  $('.pm-select2').each(function(){
    try{ initSelect2($(this)); }catch(e){ if(window.console) console.warn('Select2 init falhou:', e); }
  });

  // ===== Diferença (anos, meses, dias) da etapa =====
  function diffYMD(from){ if(!from) return null; var p=from.split('-'); if(p.length!==3) return null; var y=+p[0], m=+p[1]-1, d=+p[2]; var s=new Date(y,m,d); if(isNaN(s.getTime())) return null; var n=new Date(); var Y=n.getFullYear()-s.getFullYear(); var M=n.getMonth()-s.getMonth(); var D=n.getDate()-s.getDate(); if(D<0){ var pm=new Date(n.getFullYear(), n.getMonth(), 0); D+=pm.getDate(); M--; } if(M<0){ M+=12; Y--; } if(Y<0) return null; return {years:Y, months:M, days:D}; }
  function renderEtapaDiff(){ var v=$('#pm_data_inicio').val(); var $o=$('#pm-etapa-diff'); if(!$o.length) return; var r=diffYMD(v); if(!r){ $o.text(''); return; } var y=r.years+' ano'+(r.years===1?'':'s'); var m=r.months+' mês'+(r.months===1?'':'es'); var d=r.days+' dia'+(r.days===1?'':'s'); $o.text(y+', '+m+' e '+d+' nesta etapa'); }
  $(document).on('change input','#pm_data_inicio',renderEtapaDiff); renderEtapaDiff();

  // ===== Modal histórico =====
  var $modalH=$('#pm-hist-modal'), $histBody=$('#pm-hist-body'), currentRid=null;
  function loadHist(id,p){ currentRid=id; $histBody.text('Carregando...'); $.ajax({url:PM_CONFIG.ajax_url, method:'GET', dataType:'json', timeout:15000, data: ajaxData({action:'pm_acomp_history', registro_id:id, paged:p||1})})
    .done(function(resp){ if(resp&&resp.success&&resp.data&&resp.data.html){ $histBody.html(resp.data.html); } else { $histBody.html('<p>Não foi possível carregar o histórico.</p>'); } })
    .fail(function(xhr){ $histBody.html('<p>Falha ao carregar ('+(xhr.status||'erro')+').</p>'); }); }
  $(document).on('click','.pm-view-hist',function(){ var id=$(this).data('id'), name=$(this).data('name'); $modalH.find('h2').remove(); $histBody.before('<h2>Histórico — '+(name||'')+'</h2>'); $modalH.show(); loadHist(id,1); });
  $(document).on('click','.pm-hist-prev,.pm-hist-next',function(){ var p=$(this).data('paged'); if(!currentRid) return; loadHist(currentRid,p); });
  $(document).on('click','.pm-modal-close',function(){ $(this).closest('.pm-modal').hide(); });
  $(document).on('click','.pm-modal',function(e){ if(e.target===this) $(this).hide(); });
});
