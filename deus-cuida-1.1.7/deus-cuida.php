<?php
/**
 * Plugin Name: Deus Cuida
 * Description: Gestão de membros e acompanhamentos (admin). v1.1.7 — Hotfixes consolidados + opção de Assets Locais (Select2/DataTables) sem CDN.
 * Version: 1.1.7
 * Author: Bethânia Tech
 * Text Domain: pasta-manager
 */

if ( ! defined('ABSPATH') ) { exit; }

// ---- Constantes ----
if ( ! defined('PM_VERSION') ) define('PM_VERSION', '1.1.7');
if ( ! defined('PM_CAP_ACCESS') )      define('PM_CAP_ACCESS', 'pm_access');
if ( ! defined('PM_CAP_FRONT_CREATE') ) define('PM_CAP_FRONT_CREATE', 'pm_front_create');
if ( ! defined('PM_CAP_FRONT_EDIT') )   define('PM_CAP_FRONT_EDIT', 'pm_front_edit');
if ( ! defined('PM_CAP_AUDIT_VIEW') )   define('PM_CAP_AUDIT_VIEW', 'pm_audit_view');
if ( ! defined('PM_CAP_ACOMP_CREATE') ) define('PM_CAP_ACOMP_CREATE', 'pm_acomp_create');
if ( ! defined('PM_CAP_ACOMP_EDIT') )   define('PM_CAP_ACOMP_EDIT', 'pm_acomp_edit');

// ---- Helpers ----
function pm_safe_redirect_fallback( $url ){ if ( ! headers_sent() ) { wp_safe_redirect( $url ); exit; } echo '<meta http-equiv="refresh" content="0;url='.esc_url($url).'" />'; }
function pm_json($arr){ return wp_json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function pm_parse_date($v){ $v=trim((string)$v); if($v==='') return null; if(preg_match('~^(\d{2})/(\d{2})/(\d{4})$~',$v,$m)) return $m[3].'-'.$m[2].'-'.$m[1]; if(preg_match('~^(\d{4})-(\d{2})-(\d{2})$~',$v)) return $v; return null; }
function pm_format_date_br($v,$t=false){ if(!$v) return ''; $x=strtotime($v); if(!$x) return ''; return $t?date('d/m/Y H:i',$x):date('d/m/Y',$x); }
function pm_human_diff_months_days($start){ if(!$start) return ''; $ts=current_time('timestamp'); $now=new DateTime(); $now->setTimestamp($ts); try{ $begin=new DateTime($start);}catch(Exception $e){return '';} $diff=$begin->diff($now); $months=$diff->y*12+$diff->m; $days=$diff->d; return ($months>0?$months.' mês'.($months>1?'es':''):'0 meses').' e '.$days.' dia'.($days!=1?'s':''); }
function pm_log_audit($action,$entity,$entity_id=null,$before=null,$after=null){ global $wpdb; $t=$wpdb->prefix.'pasta_audit'; $wpdb->insert($t,[ 'user_id'=>get_current_user_id(),'action'=>$action,'entity'=>$entity,'entity_id'=>$entity_id,'data_before'=>$before?pm_json($before):null,'data_after'=>$after?pm_json($after):null,'created_at'=>current_time('mysql') ]); }

// ---- Opções ----
function pm_get_members_source(){ $v = get_option('pm_members_source', 'local'); return in_array($v, ['local','ajax'], true)? $v : 'local'; }
function pm_get_assets_source(){ $v = get_option('pm_assets_source', 'cdn'); return in_array($v, ['cdn','local'], true)? $v : 'cdn'; }

// ---- Ativação & Migrações ----
$pm_db_version = '1.1.7';
register_activation_hook(__FILE__, 'pm_activate');
function pm_activate(){
    global $wpdb, $pm_db_version;
    $t =$wpdb->prefix.'pasta_registros';
    $ta=$wpdb->prefix.'pasta_acompanhamentos';
    $tr=$wpdb->prefix.'pasta_recantos';
    $tu=$wpdb->prefix.'pasta_audit';
    $cc=$wpdb->get_charset_collate();
    require_once ABSPATH.'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        nome VARCHAR(190) NOT NULL,
        contato VARCHAR(30) NULL,
        nivel_pertenca VARCHAR(40) NULL,
        status VARCHAR(20) NULL,
        estado_civil VARCHAR(40) NULL,
        elo VARCHAR(20) NULL,
        documento VARCHAR(60) NULL,
        data_acolhimento DATE NULL,
        data_inicio_etapa DATE NULL,
        ferias TINYINT(1) DEFAULT 0,
        recanto VARCHAR(190) NULL,
        responsavel VARCHAR(190) NULL,
        cartas LONGTEXT NULL,
        cartas_file_id BIGINT UNSIGNED NULL,
        foto_id BIGINT UNSIGNED NULL,
        registro_acomp LONGTEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        KEY nome_idx(nome),
        KEY recanto_idx(recanto),
        KEY updated_idx(updated_at)
    ) $cc;");

    dbDelta("CREATE TABLE $ta (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        registro_id BIGINT UNSIGNED NOT NULL,
        data DATETIME NOT NULL,
        descricao LONGTEXT NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id), KEY reg_idx(registro_id), KEY reg_data_idx(registro_id, data)
    ) $cc;");

    dbDelta("CREATE TABLE $tr (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        nome VARCHAR(190) NOT NULL,
        slug VARCHAR(200) NOT NULL,
        PRIMARY KEY(id), UNIQUE KEY slug_unique(slug)
    ) $cc;");

    dbDelta("CREATE TABLE $tu (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NULL,
        action VARCHAR(50) NOT NULL,
        entity VARCHAR(50) NOT NULL,
        entity_id BIGINT UNSIGNED NULL,
        data_before LONGTEXT NULL,
        data_after LONGTEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id), KEY entity_idx(entity,entity_id), KEY action_idx(action), KEY user_idx(user_id)
    ) $cc;");

    // garantir novas colunas
    $cols = $wpdb->get_col("DESC $t", 0);
    if ($cols){
        if (!in_array('nivel_pertenca',$cols,true)) $wpdb->query("ALTER TABLE $t ADD COLUMN nivel_pertenca VARCHAR(40) NULL AFTER contato");
        if (!in_array('status',$cols,true))         $wpdb->query("ALTER TABLE $t ADD COLUMN status VARCHAR(20) NULL AFTER nivel_pertenca");
        if (!in_array('estado_civil',$cols,true))   $wpdb->query("ALTER TABLE $t ADD COLUMN estado_civil VARCHAR(40) NULL AFTER status");
        if (!in_array('elo',$cols,true))            $wpdb->query("ALTER TABLE $t ADD COLUMN elo VARCHAR(20) NULL AFTER estado_civil");
        if (!in_array('documento',$cols,true))      $wpdb->query("ALTER TABLE $t ADD COLUMN documento VARCHAR(60) NULL AFTER elo");
        if (!in_array('data_acolhimento',$cols,true)) $wpdb->query("ALTER TABLE $t ADD COLUMN data_acolhimento DATE NULL AFTER documento");
        if (!in_array('data_inicio_etapa',$cols,true)) $wpdb->query("ALTER TABLE $t ADD COLUMN data_inicio_etapa DATE NULL AFTER data_acolhimento");
        if (!in_array('recanto',$cols,true))        $wpdb->query("ALTER TABLE $t ADD COLUMN recanto VARCHAR(190) NULL AFTER ferias");
        if (!in_array('responsavel',$cols,true))    $wpdb->query("ALTER TABLE $t ADD COLUMN responsavel VARCHAR(190) NULL AFTER recanto");
        if (!in_array('cartas',$cols,true))         $wpdb->query("ALTER TABLE $t ADD COLUMN cartas LONGTEXT NULL AFTER responsavel");
        if (!in_array('cartas_file_id',$cols,true)) $wpdb->query("ALTER TABLE $t ADD COLUMN cartas_file_id BIGINT UNSIGNED NULL AFTER cartas");
        if (!in_array('foto_id',$cols,true))        $wpdb->query("ALTER TABLE $t ADD COLUMN foto_id BIGINT UNSIGNED NULL AFTER cartas_file_id");
        $wpdb->query("ALTER TABLE $t ADD KEY updated_idx(updated_at)");
    }

    $cols2 = $wpdb->get_col("DESC $ta", 0);
    if ($cols2 && !in_array('created_by',$cols2,true)) $wpdb->query("ALTER TABLE $ta ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER descricao");

    // Tentar criar UNIQUE no nome se não houver duplicatas
    $dups = (int)$wpdb->get_var("SELECT COUNT(*) FROM (SELECT nome, COUNT(*) c FROM $t GROUP BY nome HAVING c>1) x");
    if ($dups===0){
        $idx = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM $t WHERE Key_name=%s",'unique_nome'));
        if (!$idx){ $wpdb->query("ALTER TABLE $t ADD UNIQUE KEY unique_nome (nome)"); }
    }

    // Seed recantos
    pm_seed_default_recantos();

    // Opções padrão
    if (get_option('pm_members_source', null)===null){ add_option('pm_members_source','local'); }
    if (get_option('pm_assets_source', null)===null){ add_option('pm_assets_source','cdn'); }

    update_option('pm_db_version',$pm_db_version);
}

add_action('plugins_loaded', function(){
    $stored = get_option('pm_db_version');
    if ($stored !== '1.1.7'){ pm_activate(); }
});

function pm_seed_default_recantos(){
    global $wpdb; $t=$wpdb->prefix.'pasta_recantos';
    $defs=['São Joao Batista - SC','Curitiba - PR','Irati - PR','Guarapuava - PR','Cianorte - PR','Lorena - SP','Italva - RJ','Uberlândia - MG'];
    foreach($defs as $r){ $slug=sanitize_title($r); $e=$wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE slug=%s",$slug)); if(!$e){ $wpdb->insert($t,['nome'=>$r,'slug'=>$slug]); } }
}

// ---- Menus ----
add_action('admin_menu', function(){
    add_menu_page(__('Gestão de Membros','pasta-manager'), __('Membros','pasta-manager'), PM_CAP_ACCESS, 'pasta-manager', 'pm_render_list_page', 'dashicons-groups', 25);
    add_submenu_page('pasta-manager', __('Adicionar Novo','pasta-manager'), __('Adicionar Novo','pasta-manager'), PM_CAP_ACCESS, 'pasta-manager-add', 'pm_render_form_page');
    add_submenu_page('pasta-manager', __('Acompanhamentos','pasta-manager'), __('Acompanhamentos','pasta-manager'), PM_CAP_ACOMP_CREATE, 'pasta-acomp-membros', 'pm_render_acomp_list_page');
    // Configurações apenas para Admin WP
    add_submenu_page('pasta-manager', __('Configurações','pasta-manager'), __('Configurações','pasta-manager'), 'manage_options', 'pasta-config', 'pm_render_config_page');
    add_submenu_page('pasta-manager', __('Evolução','pasta-manager'), __('Evolução','pasta-manager'), PM_CAP_AUDIT_VIEW, 'pasta-evolucao', 'pm_render_evolucao_page');
});

// ---- Assets (CDN vs Local) ----
add_action('admin_enqueue_scripts', function($hook){
    $allowed = ['toplevel_page_pasta-manager','pasta-manager_page_pasta-manager-add','pasta-manager_page_pasta-acomp-membros','pasta-manager_page_pasta-evolucao','pasta-manager_page_pasta-config'];
    if(!in_array($hook,$allowed,true)) return;

    $src = pm_get_assets_source();

    wp_enqueue_style('pm-style', plugins_url('assets/style.css', __FILE__), [], PM_VERSION);
    wp_enqueue_script('jquery');

    if ($src==='local'){
        // Local vendor
        wp_enqueue_style('select2-css', plugins_url('assets/vendor/select2/select2.min.css', __FILE__), [], '4.1.0');
        wp_enqueue_script('select2-js', plugins_url('assets/vendor/select2/select2.min.js', __FILE__), ['jquery'], '4.1.0', true);

        wp_enqueue_style('dt-css', plugins_url('assets/vendor/datatables/jquery.dataTables.min.css', __FILE__), [], '1.13.8');
        wp_enqueue_script('dt-js', plugins_url('assets/vendor/datatables/jquery.dataTables.min.js', __FILE__), ['jquery'], '1.13.8', true);

        wp_enqueue_style('dt-btn-css', plugins_url('assets/vendor/buttons/dataTables.buttons.min.css', __FILE__), [], '2.4.2');
        wp_enqueue_script('dt-btn-js', plugins_url('assets/vendor/buttons/dataTables.buttons.min.js', __FILE__), ['dt-js'], '2.4.2', true);

        wp_enqueue_script('jszip', plugins_url('assets/vendor/jszip/jszip.min.js', __FILE__), [], '3.7.1', true);
        wp_enqueue_script('dt-btn-html5', plugins_url('assets/vendor/buttons/buttons.html5.min.js', __FILE__), ['dt-btn-js','jszip'], '2.4.2', true);
        wp_enqueue_script('dt-btn-print', plugins_url('assets/vendor/buttons/buttons.print.min.js', __FILE__), ['dt-btn-js'], '2.4.2', true);
    } else {
        // CDN vendor
        wp_enqueue_style('dt-css','https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css',[], '1.13.8');
        wp_enqueue_script('dt-js','https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',['jquery'],'1.13.8',true);
        wp_enqueue_style('dt-btn-css','https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css',[], '2.4.2');
        wp_enqueue_script('dt-btn-js','https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js',['dt-js'],'2.4.2',true);
        wp_enqueue_script('jszip','https://cdnjs.cloudflare.com/ajax/libs/jszip/3.7.1/jszip.min.js',[],'3.7.1',true);
        wp_enqueue_script('dt-btn-html5','https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js',['dt-btn-js','jszip'],'2.4.2',true);
        wp_enqueue_script('dt-btn-print','https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js',['dt-btn-js'],'2.4.2',true);
        wp_enqueue_style('select2-css','https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',[],'4.1.0');
        wp_enqueue_script('select2-js','https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',['jquery'],'4.1.0',true);
    }

    // Nosso JS
    wp_enqueue_script('pm-admin', plugins_url('assets/admin.js', __FILE__), ['jquery','dt-js','dt-btn-js','dt-btn-html5','dt-btn-print','select2-js'], PM_VERSION, true);
    wp_localize_script('pm-admin','PM_CONFIG',[ 'nonce'=> wp_create_nonce('pm_nonce'), 'i18n_url'=>'https://cdn.datatables.net/plug-ins/1.13.8/i18n/pt-BR.json', 'ajax_url'=> admin_url('admin-ajax.php'), 'export_filename'=>'membros_'.date('Ymd_His'), 'confirm_remove'=> __('Tem certeza que deseja remover este registro? Esta ação não pode ser desfeita.','pasta-manager') ]);

    if ($src==='local'){
        // Aviso se placeholders estiverem ativos (checagem best-effort)
        $ph = plugin_dir_path(__FILE__).'assets/vendor/select2/select2.min.js';
        if (file_exists($ph)){
            $content = @file_get_contents($ph);
            if ($content && strpos($content,'Placeholder local asset')!==false){
                add_action('admin_notices', function(){
                    echo '<div class="notice notice-warning"><p><strong>Deus Cuida:</strong> Você selecionou <em>Assets: Local</em>, mas os arquivos atuais são placeholders. Substitua pelos arquivos oficiais em <code>assets/vendor/...</code> ou troque para <em>CDN</em> em Configurações.</p></div>';
                });
            }
        }
    }
});

// ---- AJAX ----
function pm_can_search_membros(){ return current_user_can(PM_CAP_ACCESS) || current_user_can(PM_CAP_ACOMP_CREATE) || current_user_can('administrator'); }
add_action('wp_ajax_pm_search_membros', function(){
    if( ! pm_can_search_membros() ) wp_send_json([], 403);
    check_ajax_referer('pm_nonce','nonce');
    global $wpdb; $t=$wpdb->prefix.'pasta_registros';
    $q=isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    if($q!==''){ $like='%'.$wpdb->esc_like($q).'%'; $rows=$wpdb->get_results($wpdb->prepare("SELECT id,nome FROM $t WHERE nome LIKE %s ORDER BY nome ASC LIMIT 300", $like)); }
    else { $rows=$wpdb->get_results("SELECT id,nome FROM $t ORDER BY nome ASC LIMIT 300"); }
    $out=[]; foreach($rows as $r){ $out[]=['id'=>intval($r->id),'text'=>$r->nome]; }
    wp_send_json($out);
});

add_action('wp_ajax_pm_acomp_history', function(){
    if( ! current_user_can(PM_CAP_AUDIT_VIEW) ) wp_send_json_error(['message'=>'Sem permissão'],403);
    check_ajax_referer('pm_nonce','nonce');
    global $wpdb; $t=$wpdb->prefix.'pasta_acompanhamentos'; $tr=$wpdb->prefix.'pasta_registros';
    $rid=isset($_GET['registro_id'])?absint($_GET['registro_id']):0; if(!$rid) wp_send_json_error(['message'=>'ID inválido'],400);
    $paged=max(1,intval($_GET['paged']??1)); $per=8; $off=($paged-1)*$per;
    $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE registro_id=%d",$rid));
    $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE registro_id=%d ORDER BY data DESC, id DESC LIMIT %d OFFSET %d",$rid,$per,$off));
    $reg=$wpdb->get_row($wpdb->prepare("SELECT nome FROM $tr WHERE id=%d",$rid));
    ob_start();
    echo '<div class="pm-timeline">';
    if($rows){
        foreach($rows as $r){
            echo '<div class="pm-tl-item">';
            echo '<div class="pm-tl-head"><span class="pm-badge pm-badge-acomp">Acomp.</span>'.esc_html(pm_format_date_br($r->data,true)).'</div>';
            echo '<div class="pm-tl-title">'.esc_html($reg?$reg->nome:('#'.$rid)).'</div>';
            echo '<div class="pm-tl-desc">'.wp_kses_post(wpautop($r->descricao)).'</div>';
            echo '</div>';
        }
        $tp=max(1,ceil($total/$per));
        echo '<div class="pm-tl-pager">';
        if($paged>1) echo '<button type="button" class="button pm-hist-prev" data-paged="'.intval($paged-1).'">« Anterior</button> ';
        echo '<span class="pm-muted">Página '.intval($paged).' de '.intval($tp).'</span> ';
        if($paged<$tp) echo '<button type="button" class="button pm-hist-next" data-paged="'.intval($paged+1).'">Próxima »</button>';
        echo '</div>';
    } else {
        echo '<p>Sem acompanhamentos cadastrados.</p>';
    }
    echo '</div>';
    $html=ob_get_clean();
    wp_send_json_success(['html'=>$html]);
});

// ---- CRUD Membros (inclui fix nulo-safe + Documento + bloqueio de duplicidade) ----
add_action('admin_post_pm_save', function(){ if(!current_user_can(PM_CAP_ACCESS)) wp_die('Sem permissão'); check_admin_referer('pm_form'); pm_handle_save_member('admin'); });
function pm_handle_save_member($context='admin'){
    global $wpdb; $t=$wpdb->prefix.'pasta_registros';
    $id = isset($_POST['id']) ? absint($_POST['id']) : 0; if ($context!=='admin' && $context!=='front_edit'){ $id = 0; }

    $nome        = sanitize_text_field(wp_unslash($_POST['nome'] ?? ''));
    $cont        = sanitize_text_field(wp_unslash($_POST['contato'] ?? ''));
    $cont        = preg_replace('~[^\+\d\s\-\(\)]~','',$cont);
    $nivel       = sanitize_text_field(wp_unslash($_POST['nivel_pertenca'] ?? ''));
    $status      = sanitize_text_field(wp_unslash($_POST['status'] ?? 'Ativo'));
    $estado_civil= sanitize_text_field(wp_unslash($_POST['estado_civil'] ?? ''));
    $elo         = sanitize_text_field(wp_unslash($_POST['elo'] ?? ''));
    $documento   = sanitize_text_field(wp_unslash($_POST['documento'] ?? ''));
    $acolh       = pm_parse_date(wp_unslash($_POST['data_acolhimento'] ?? ''));
    $inicio      = pm_parse_date(wp_unslash($_POST['data_inicio_etapa'] ?? ''));
    $recanto     = sanitize_text_field(wp_unslash($_POST['recanto'] ?? ''));
    $responsavel = sanitize_text_field(wp_unslash($_POST['responsavel'] ?? ''));
    $cartas      = wp_kses_post(wp_unslash($_POST['cartas'] ?? ''));

    $required = [];
    if($nome==='')        $required[]='Nome';
    if($cont==='')        $required[]='Contato';
    if($nivel==='')       $required[]='Nível de pertença';
    if($elo==='')         $required[]='Elo';
    if($recanto==='')     $required[]='Recanto';
    if($responsavel==='') $required[]='Responsável';
    if($required){ wp_die('Campos obrigatórios faltando: '.esc_html(implode(', ', $required)).'.'); }

    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE nome=%s AND id<>%d", $nome, $id));
    if ((int)$exists>0){ wp_die('Já existe um cadastro com este Nome. Altere o nome para prosseguir.'); }

    $data = [
        'nome'               => $nome,
        'contato'            => $cont,
        'nivel_pertenca'     => $nivel,
        'status'             => $status,
        'estado_civil'       => $estado_civil,
        'elo'                => $elo,
        'documento'          => $documento,
        'data_acolhimento'   => $acolh,
        'data_inicio_etapa'  => $inicio,
        'recanto'            => $recanto,
        'responsavel'        => $responsavel,
        'cartas'             => $cartas,
    ];

    $before = null; if ($id){ $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A); }

    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/image.php';
    $overrides_files = [ 'test_form'=>false, 'mimes'=> [ 'pdf'=>'application/pdf', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png', 'doc'=>'application/msword', 'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ] ];
    $max_mb = 8;

    if (!empty($_POST['remove_cartas_file']) && $id && !empty($before['cartas_file_id'])){ wp_delete_attachment((int)$before['cartas_file_id'], true); $data['cartas_file_id'] = null; }
    if (!empty($_POST['remove_foto']) && $id && !empty($before['foto_id'])){ wp_delete_attachment((int)$before['foto_id'], true); $data['foto_id'] = null; }

    if (!empty($_FILES['cartas_file']['name'])){
        if ((int)$_FILES['cartas_file']['size'] > $max_mb*1024*1024) wp_die('Arquivo muito grande (máx. 8MB).');
        $up = wp_handle_upload($_FILES['cartas_file'], $overrides_files);
        if (!isset($up['error'])){
            $ft = wp_check_filetype(basename($up['file']), null);
            $aid = wp_insert_attachment([ 'guid'=>$up['url'], 'post_mime_type'=>$ft['type'], 'post_title'=>sanitize_file_name(basename($up['file'])), 'post_status'=>'inherit' ], $up['file']);
            if(function_exists('wp_generate_attachment_metadata')){ $amd=@wp_generate_attachment_metadata($aid,$up['file']); if($amd) wp_update_attachment_metadata($aid,$amd); }
            $data['cartas_file_id']=$aid;
        }
    }

    if (!empty($_FILES['foto']['name'])){
        if ((int)$_FILES['foto']['size'] > $max_mb*1024*1024) wp_die('Arquivo de imagem muito grande (máx. 8MB).');
        $up = wp_handle_upload($_FILES['foto'], $overrides_files);
        if (!isset($up['error'])){
            $ft = wp_check_filetype(basename($up['file']), null);
            $aid = wp_insert_attachment([ 'guid'=>$up['url'], 'post_mime_type'=>$ft['type'], 'post_title'=>sanitize_file_name(basename($up['file'])), 'post_status'=>'inherit' ], $up['file']);
            if(function_exists('wp_generate_attachment_metadata')){ $amd=@wp_generate_attachment_metadata($aid,$up['file']); if($amd) wp_update_attachment_metadata($aid,$amd); }
            $data['foto_id']=$aid;
        }
    }

    if ($id){ $wpdb->update($t, $data, ['id'=>$id]); pm_log_audit('update','membro',$id,$before,$data); }
    else { $wpdb->insert($t, $data); $new_id=$wpdb->insert_id; pm_log_audit('create','membro',$new_id,null,$data); }

    pm_safe_redirect_fallback( add_query_arg(['page'=>'pasta-manager','updated'=>'1'], admin_url('admin.php')) );
}

add_action('admin_post_pm_delete', function(){ if(!current_user_can(PM_CAP_ACCESS)) wp_die('Sem permissão'); check_admin_referer('pm_delete'); global $wpdb; $t=$wpdb->prefix.'pasta_registros'; $id=isset($_GET['id'])?absint($_GET['id']):0; if($id){ $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$id), ARRAY_A); $wpdb->delete($t,['id'=>$id]); pm_log_audit('delete','membro',$id,$before,null); } pm_safe_redirect_fallback( add_query_arg(['page'=>'pasta-manager','deleted'=>'1'], admin_url('admin.php')) ); });

// ---- Páginas ----
function pm_render_list_page(){ if(!current_user_can(PM_CAP_ACCESS)) return; echo pm_render_list_page_inner(); }

function pm_render_list_page_inner(){
    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">'.esc_html__('Membros','pasta-manager').'</h1> ';
    echo ' <a href="'.esc_url(admin_url('admin.php?page=pasta-manager-add')).'" class="page-title-action">'.esc_html__('Adicionar Novo','pasta-manager').'</a>';
    echo '<hr class="wp-header-end" />';
    echo pm_render_list_table(true);
    $simple_url = add_query_arg(['page'=>'pasta-manager','layout'=>'simple'], admin_url('admin.php'));
    $full_url   = add_query_arg(['page'=>'pasta-manager','layout'=>'full'], admin_url('admin.php'));
    echo '<p><a class="button" href="'.esc_url($simple_url).'">'.esc_html__('Visão simplificada','pasta-manager').'</a> ';
    echo '<a class="button" href="'.esc_url($full_url).'">'.esc_html__('Visão completa','pasta-manager').'</a></p>';
    echo '</div>';
}

function pm_render_list_table($admin=false){
    global $wpdb; $table=$wpdb->prefix.'pasta_registros'; $ta=$wpdb->prefix.'pasta_acompanhamentos';
    $s = trim(sanitize_text_field($_GET['s'] ?? ''));
    $recanto = trim(sanitize_text_field($_GET['recanto'] ?? ''));
    $nivel = trim(sanitize_text_field($_GET['nivel'] ?? ''));
    $f_status = trim(sanitize_text_field($_GET['status'] ?? ''));
    $f_elo = trim(sanitize_text_field($_GET['elo'] ?? ''));

    $where='WHERE 1=1'; $params=[];
    if ($s!==''){ $like='%'.$wpdb->esc_like($s).'%'; $where.=" AND (r.nome LIKE %s OR r.recanto LIKE %s OR r.responsavel LIKE %s OR r.cartas LIKE %s OR r.registro_acomp LIKE %s OR r.contato LIKE %s OR r.nivel_pertenca LIKE %s OR r.documento LIKE %s)"; array_push($params,$like,$like,$like,$like,$like,$like,$like,$like); }
    if ($recanto!==''){ $where.=' AND r.recanto = %s'; $params[]=$recanto; }
    if ($nivel!==''){ $where.=' AND r.nivel_pertenca = %s'; $params[]=$nivel; }
    if ($f_status!==''){ $where.=' AND r.status = %s'; $params[]=$f_status; }
    if ($f_elo!==''){ $where.=' AND r.elo = %s'; $params[]=$f_elo; }

    $sql = "SELECT r.*, a.data AS last_acomp_data, a.descricao AS last_acomp_desc FROM $table r LEFT JOIN (
                SELECT t1.* FROM $ta t1 INNER JOIN (
                    SELECT registro_id, MAX(data) AS max_data FROM $ta GROUP BY registro_id
                ) t2 ON t1.registro_id = t2.registro_id AND t1.data = t2.max_data
            ) a ON a.registro_id = r.id $where ORDER BY r.updated_at DESC";

    $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

    $recantos = $wpdb->get_col("SELECT nome FROM {$wpdb->prefix}pasta_recantos ORDER BY nome ASC");
    $nivel_opts = array('Auxiliar de missão','Vocacionado','Aspirante','Discípulo','Consagrado');
    $status_opts = array('Ativo','Inativo');
    $elo_opts = array('Vida','Aliança');
    $has_actions = $admin || current_user_can(PM_CAP_FRONT_EDIT);

    $layout = isset($_GET['layout']) ? sanitize_text_field($_GET['layout']) : 'simple';

    ob_start();
    echo '<div class="pm-filters">';
    echo '<form method="get">';
    echo '<input type="hidden" name="page" value="pasta-manager" />';
    echo '<input type="search" name="s" value="'.esc_attr($s).'" placeholder="'.esc_attr__('Buscar','pasta-manager').'" class="regular-text" /> ';

    echo '<select name="recanto">';
    echo '<option value="">'.esc_html__('Todos os recantos','pasta-manager').'</option>';
    foreach($recantos as $r){ $sel=selected($r,$recanto,false); echo '<option'.$sel.'>'.esc_html($r).'</option>'; }
    echo '</select> ';

    echo '<select name="nivel">';
    echo '<option value="">'.esc_html__('Todos os níveis','pasta-manager').'</option>';
    foreach($nivel_opts as $nv){ $sel=selected($nv,$nivel,false); echo '<option'.$sel.'>'.esc_html($nv).'</option>'; }
    echo '</select> ';

    echo '<select name="status">';
    echo '<option value="">'.esc_html__('Todos os status','pasta-manager').'</option>';
    foreach($status_opts as $op){ $sel=selected($op,$f_status,false); echo '<option'.$sel.'>'.esc_html($op).'</option>'; }
    echo '</select> ';

    echo '<select name="elo">';
    echo '<option value="">'.esc_html__('Todos os elos','pasta-manager').'</option>';
    foreach($elo_opts as $op){ $sel=selected($op,$f_elo,false); echo '<option'.$sel.'>'.esc_html($op).'</option>'; }
    echo '</select> ';

    submit_button(__('Filtrar','pasta-manager'), 'secondary', '', false);
    echo '</form>';
    echo '</div>';

    echo '<table id="pm-table" class="widefat striped fixed">';
    $headers = ($layout==='simple') ? ['Foto','Nome','Nível','Elo','Recanto'] : ['Foto','Nome','Contato','Status','Nível','Estado civil','Elo','Documento','Acolhimento','Início etapa','Recanto','Responsável','Observações','Carta (download)','Último acompanhamento'];
    if ($has_actions) $headers[]='Ações';

    echo '<thead><tr>'; foreach($headers as $c){ echo '<th>'.esc_html($c).'</th>'; } echo '</tr></thead><tbody>';

    if ($rows){ foreach($rows as $row){
        $file_link='—';
        if(!empty($row->cartas_file_id)){
            $url=wp_get_attachment_url((int)$row->cartas_file_id);
            if($url){ $fname=basename(get_attached_file((int)$row->cartas_file_id)); $file_link='<a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html__('Baixar','pasta-manager').'</a> <span class="pm-file-name">'.esc_html($fname).'</span>'; }
        }
        $last_acomp = (!empty($row->last_acomp_data) && !empty($row->last_acomp_desc)) ? (esc_html(pm_format_date_br($row->last_acomp_data,true)).': '.esc_html(wp_trim_words(wp_strip_all_tags($row->last_acomp_desc),18,'…'))) : 'Sem registros';
        $inicio = pm_format_date_br($row->data_inicio_etapa); $diff = pm_human_diff_months_days($row->data_inicio_etapa); $inicio_html = esc_html($inicio).($diff? ' ('.esc_html($diff).')' : '');
        $obs_trim = esc_html(wp_trim_words(wp_strip_all_tags($row->cartas), 16, '…'));
        $thumb = '';
        if (!empty($row->foto_id)){
            $img = wp_get_attachment_image_src((int)$row->foto_id, 'thumbnail');
            if ($img){ $thumb = '<img class="'.(($layout==='simple')?'pm-photo-s':'').'" src="'.esc_url($img[0]).'" width="'.intval($img[1]).'" height="'.intval($img[2]).'" alt="" />'; }
        }
        echo '<tr>';
        echo '<td>'.($thumb?:'').'</td>';
        echo '<td>'.esc_html($row->nome).'</td>';
        if($layout!=='simple'){ echo '<td>'.esc_html($row->contato).'</td>'; echo '<td>'.esc_html($row->status ?: '—').'</td>'; }
        echo '<td>'.esc_html($row->nivel_pertenca).'</td>';
        if($layout!=='simple'){ echo '<td>'.esc_html($row->estado_civil ?: '—').'</td>'; }
        echo '<td>'.esc_html($row->elo ?: '—').'</td>';
        if($layout!=='simple'){ echo '<td>'.esc_html($row->documento ?: '—').'</td>'; echo '<td>'.esc_html(pm_format_date_br($row->data_acolhimento)).'</td>'; echo '<td>'.$inicio_html.'</td>'; }
        echo '<td>'.esc_html($row->recanto).'</td>';
        if($layout!=='simple'){ echo '<td>'.esc_html($row->responsavel).'</td>'; echo '<td>'.$obs_trim.'</td>'; echo '<td>'.$file_link.'</td>'; echo '<td>'.$last_acomp.'</td>'; }
        if ($has_actions){
            $edit_url = admin_url('admin.php?page=pasta-manager-add&edit=' . intval($row->id));
            $del_url  = wp_nonce_url(admin_url('admin-post.php?action=pm_delete&id=' . intval($row->id)), 'pm_delete');
            echo '<td><div class="pm-actions">';
            echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=pasta-manager-add&view=' . intval($row->id))).'">'.esc_html__('Detalhes','pasta-manager').'</a>';
            echo '<a class="button" href="'.esc_url($edit_url).'">'.esc_html__('Editar','pasta-manager').'</a>';
            echo '<a class="button pm-view-hist" href="#" data-id="'.intval($row->id).'" data-name="'.esc_attr($row->nome).'">'.esc_html__('Histórico','pasta-manager').'</a>';
            echo '<a class="button pm-delete" href="'.esc_url($del_url).'">'.esc_html__('Remover','pasta-manager').'</a>';
            echo '</div></td>';
        }
        echo '</tr>';
    } } else {
        echo '<tr><td colspan="'.count($headers).'">'.esc_html__('Sem registros.','pasta-manager').'</td></tr>';
    }
    echo '</tbody></table>';

    echo '<div id="pm-hist-modal" class="pm-modal" style="display:none;">'
        .'<div class="pm-modal-content">'
        .'<span class="pm-modal-close" aria-label="Fechar">×</span>'
        .'<div id="pm-hist-body">Carregando...</div>'
        .'</div></div>';

    return ob_get_clean();
}

function pm_render_form_page(){
    if(!current_user_can(PM_CAP_ACCESS)) return;
    global $wpdb; $t=$wpdb->prefix.'pasta_registros';
    $viewing=isset($_GET['view'])?absint($_GET['view']):0;
    if($viewing){
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$viewing)); if(!$row){ echo '<div class="notice notice-error"><p>Registro não encontrado.</p></div>'; return; }
        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Detalhes do membro','pasta-manager').'</h1>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=pasta-manager')).'">'.esc_html__('‹ Voltar','pasta-manager').'</a> ';
        echo '<a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=pasta-manager-add&edit=' . intval($viewing))).'">'.esc_html__('Editar','pasta-manager').'</a></p>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Foto</th><td>'; if(!empty($row->foto_id)){ $img=wp_get_attachment_image_src((int)$row->foto_id,'thumbnail'); if($img){ echo '<img src="'.esc_url($img[0]).'" width="'.intval($img[1]).'" height="'.intval($img[2]).'" alt="" />'; } } else { echo '—'; } echo '</td></tr>';
        echo '<tr><th>Nome</th><td>'.esc_html($row->nome).'</td></tr>';
        echo '<tr><th>Documento</th><td>'.esc_html($row->documento?:'—').'</td></tr>';
        echo '<tr><th>Contato</th><td>'.esc_html($row->contato).'</td></tr>';
        echo '<tr><th>Status</th><td>'.esc_html($row->status?:'—').'</td></tr>';
        echo '<tr><th>Nível</th><td>'.esc_html($row->nivel_pertenca).'</td></tr>';
        echo '<tr><th>Estado civil</th><td>'.esc_html($row->estado_civil?:'—').'</td></tr>';
        echo '<tr><th>Elo</th><td>'.esc_html($row->elo?:'—').'</td></tr>';
        echo '<tr><th>Data de acolhimento</th><td>'.esc_html(pm_format_date_br($row->data_acolhimento)).'</td></tr>';
        echo '<tr><th>Data de início da etapa</th><td>'.esc_html(pm_format_date_br($row->data_inicio_etapa)).'</td></tr>';
        echo '<tr><th>Recanto</th><td>'.esc_html($row->recanto).'</td></tr>';
        echo '<tr><th>Responsável</th><td>'.esc_html($row->responsavel).'</td></tr>';
        echo '<tr><th>Observações</th><td>'.wp_kses_post(wpautop($row->cartas)).'</td></tr>';
        echo '</tbody></table>';
        echo '</div>';
        return;
    }

    $editing=isset($_GET['edit'])?absint($_GET['edit']):0; $row=null; if($editing){ $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$editing)); if(!$row){ echo '<div class="notice notice-error"><p>Registro não encontrado.</p></div>'; $editing=0; } }

    $existing = ($row && !empty($row->cartas_file_id)) ? (int)$row->cartas_file_id : 0; $file_html=''; if($existing){ $url=wp_get_attachment_url((int)$existing); if($url){ $fname=basename(get_attached_file((int)$existing)); $file_html='<p>Arquivo atual: <a target="_blank" rel="noopener" href="'.esc_url($url).'">'.esc_html($fname).'</a></p><label><input type="checkbox" name="remove_cartas_file" value="1"/> Remover arquivo atual</label>'; } }
    $foto_existing = ($row && !empty($row->foto_id)) ? (int)$row->foto_id : 0; $foto_html=''; if($foto_existing){ $img=wp_get_attachment_image_src((int)$foto_existing,'thumbnail'); if($img){ $foto_html='<p>Foto atual: <img src="'.esc_url($img[0]).'" width="'.intval($img[1]).'" height="'.intval($img[2]).'" alt="" /></p><label><input type="checkbox" name="remove_foto" value="1"/> Remover foto atual</label>'; } }

    $nivel_val = $row ? $row->nivel_pertenca : ''; $status_val = $row ? ($row->status?:'Ativo') : 'Ativo'; $ec_val = $row ? ($row->estado_civil?:'') : ''; $elo_val = $row ? ($row->elo?:'') : ''; $sel_rec = $row ? ($row->recanto?:'') : '';

    $recantos = $wpdb->get_col("SELECT nome FROM {$wpdb->prefix}pasta_recantos ORDER BY nome ASC");

    echo '<div class="wrap"><h1>'.($editing?esc_html__('Editar membro','pasta-manager'):esc_html__('Adicionar novo membro','pasta-manager')).'</h1>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="pm_save" />'; if($editing) echo '<input type="hidden" name="id" value="'.intval($editing).'" />'; wp_nonce_field('pm_form');

    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label for="pm_nome">'.esc_html__('Nome','pasta-manager').'</label></th><td><input name="nome" id="pm_nome" type="text" class="regular-text" value="'.esc_attr($row ? $row->nome : '').'" required /></td></tr>';

    echo '<tr><th><label for="pm_documento">'.esc_html__('Documento (CPF, RG, CNH)','pasta-manager').'</label></th><td><input name="documento" id="pm_documento" type="text" class="regular-text" value="'.esc_attr($row ? $row->documento : '').'" /></td></tr>';

    echo '<tr><th><label for="pm_contato">'.esc_html__('Contato (celular)','pasta-manager').'</label></th><td><input name="contato" id="pm_contato" type="text" class="regular-text" value="'.esc_attr($row ? $row->contato : '').'" required /><p class="description">Aceita números, +, espaço, -, ().</p></td></tr>';

    echo '<tr><th>'.esc_html__('Status','pasta-manager').'</th><td>';
    foreach(['Ativo','Inativo'] as $op){ $chk=checked($status_val,$op,false); echo '<label><input type="radio" name="status" value="'.esc_attr($op).'" '.$chk.'/> '.esc_html($op).'</label> '; }
    echo '</td></tr>';

    echo '<tr><th>'.esc_html__('Nível de pertença','pasta-manager').'</th><td>';
    echo '<select name="nivel_pertenca" class="regular-text" required>';
    foreach(['','Auxiliar de missão','Vocacionado','Aspirante','Discípulo','Consagrado'] as $nv){ $sel=selected($nivel_val,$nv,false); $label=$nv?$nv:'— Selecione —'; echo '<option value="'.esc_attr($nv).'" '.$sel.'>'.$label.'</option>'; }
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th>'.esc_html__('Estado civil','pasta-manager').'</th><td>';
    $ecs=['','Solteiro(a)','Casado(a)','Separado(a)','Divorciado(a)','Viúvo(a)'];
    echo '<select name="estado_civil" class="regular-text">'; foreach($ecs as $e){ $sel=selected($ec_val,$e,false); $label=$e?$e:'— Selecione —'; echo '<option value="'.esc_attr($e).'" '.$sel.'>'.$label.'</option>'; } echo '</select>';
    echo '</td></tr>';

    echo '<tr><th>'.esc_html__('Elo','pasta-manager').'</th><td>';
    $elos=['','Vida','Aliança']; echo '<select name="elo" class="regular-text" required>'; foreach($elos as $e){ $sel=selected($elo_val,$e,false); $label=$e?$e:'— Selecione —'; echo '<option value="'.esc_attr($e).'" '.$sel.'>'.$label.'</option>'; } echo '</select>';
    echo '</td></tr>';

    echo '<tr><th><label for="pm_data_acolh">'.esc_html__('Data de acolhimento','pasta-manager').'</label></th><td><input name="data_acolhimento" id="pm_data_acolh" type="date" class="regular-text" value="'.esc_attr($row ? $row->data_acolhimento : '').'" /></td></tr>';

    echo '<tr><th><label for="pm_data_inicio">'.esc_html__('Data de início da etapa','pasta-manager').'</label></th><td>';
    echo '<input name="data_inicio_etapa" id="pm_data_inicio" type="date" class="regular-text" value="'.esc_attr($row ? $row->data_inicio_etapa : '').'" />';
    echo '<span id="pm-etapa-diff"></span>';
    echo '</td></tr>';

    echo '<tr><th><label for="pm_recanto">'.esc_html__('Recanto','pasta-manager').'</label></th><td>';
    echo '<select id="pm_recanto" name="recanto" class="pm-select2" data-source="recantos" style="width: 320px" required>';
    echo '<option value="">— Selecione —</option>';
    foreach($recantos as $r){ $sel=selected($sel_rec,$r,false); echo '<option value="'.esc_attr($r).'" '.$sel.'>'.esc_html($r).'</option>'; }
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th><label for="pm_responsavel">'.esc_html__('Responsável','pasta-manager').'</label></th><td><input name="responsavel" id="pm_responsavel" type="text" class="regular-text" value="'.esc_attr($row ? $row->responsavel : '').'" required /></td></tr>';

    echo '<tr><th><label for="pm_cartas">'.esc_html__('Observações','pasta-manager').'</label></th><td><textarea name="cartas" id="pm_cartas" rows="5" class="large-text">'.esc_textarea($row ? $row->cartas : '').'</textarea></td></tr>';

    echo '<tr><th>'.esc_html__('Arquivo (opcional)','pasta-manager').'</th><td>'.($file_html?:'<p>Envie PDF, imagem ou DOC/DOCX.</p>').'<input type="file" name="cartas_file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" /></td></tr>';

    echo '<tr><th>'.esc_html__('Foto','pasta-manager').'</th><td>'.($foto_html?:'<p>Envie uma imagem quadrada (miniatura exibida no dashboard).</p>').'<input type="file" name="foto" accept="image/*" /></td></tr>';

    echo '</tbody></table>';
    submit_button($editing?__('Salvar alterações','pasta-manager'):__('Adicionar membro','pasta-manager'));
    echo '</form></div>';
}

function pm_render_acomp_list_page(){
    if(!current_user_can(PM_CAP_ACOMP_CREATE)) return;
    global $wpdb; $t=$wpdb->prefix.'pasta_acompanhamentos'; $tr=$wpdb->prefix.'pasta_registros';

    $view = isset($_GET['view'])?absint($_GET['view']):0;
    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Acompanhamentos','pasta-manager').'</h1>';

    if(!$view){
        echo '<h2 class="title">'.esc_html__('Novo acompanhamento','pasta-manager').'</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="pm_add_acomp" />';
        wp_nonce_field('pm_acomp');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>'.esc_html__('Membro','pasta-manager').'</th><td>';
        $source = pm_get_members_source();
        if ($source==='local'){
            $members=$wpdb->get_results("SELECT id, nome FROM $tr ORDER BY nome ASC");
            echo '<select name="registro_id" class="pm-select2" data-source="local-membros" style="width: 320px">';
            echo '<option value="">'.esc_html__('Selecione...','pasta-manager').'</option>';
            if($members){ foreach($members as $m){ echo '<option value="'.intval($m->id).'">'.esc_html($m->nome).'</option>'; } }
            echo '</select>';
            echo '<p class="description">Fonte: Local (sem AJAX)</p>';
        } else {
            echo '<select name="registro_id" class="pm-select2" data-source="membros" style="width: 320px"><option value="">'.esc_html__('Selecione ou digite para buscar...','pasta-manager').'</option></select>';
            echo '<p class="description">Fonte: AJAX (Select2)</p>';
        }
        echo '</td></tr>';
        echo '<tr><th>'.esc_html__('Data','pasta-manager').'</th><td><input type="datetime-local" name="data" /></td></tr>';
        echo '<tr><th>'.esc_html__('Descrição','pasta-manager').'</th><td><textarea name="descricao" rows="5" class="large-text"></textarea></td></tr>';
        echo '</tbody></table>';
        submit_button(__('Adicionar','pasta-manager'));
        echo '</form>';

        $rows = $wpdb->get_results("SELECT a.*, r.nome AS membro_nome, u.display_name AS user_name FROM $t a LEFT JOIN $tr r ON r.id=a.registro_id LEFT JOIN {$wpdb->users} u ON u.ID=a.created_by ORDER BY a.data DESC, a.id DESC");
        echo '<h2 class="title">'.esc_html__('Registros','pasta-manager').'</h2>';
        echo '<table class="widefat striped fixed"><thead><tr>';
        $headers = ['ID','Data/Hora','Membro','Descrição','Registrado por','Ações']; foreach($headers as $h){ echo '<th>'.esc_html($h).'</th>'; }
        echo '</tr></thead><tbody>';
        if($rows){ foreach($rows as $r){ $desc=wp_trim_words(wp_strip_all_tags($r->descricao),18,'…'); $view_url=admin_url('admin.php?page=pasta-acomp-membros&view='.intval($r->id)); echo '<tr>'; echo '<td>'.intval($r->id).'</td>'; echo '<td>'.esc_html(pm_format_date_br($r->data,true)).'</td>'; echo '<td>'.esc_html($r->membro_nome?:'—').'</td>'; echo '<td>'.esc_html($desc).'</td>'; echo '<td>'.esc_html($r->user_name?:'—').'</td>'; echo '<td><a href="'.esc_url($view_url).'">'.esc_html__('Ver','pasta-manager').'</a></td>'; echo '</tr>'; } } else { echo '<tr><td colspan="6">'.esc_html__('Sem registros.','pasta-manager').'</td></tr>'; }
        echo '</tbody></table>';
    } else {
        $row=$wpdb->get_row($wpdb->prepare("SELECT a.*, r.nome AS membro_nome, u.display_name AS user_name FROM $t a LEFT JOIN $tr r ON r.id=a.registro_id LEFT JOIN {$wpdb->users} u ON u.ID=a.created_by WHERE a.id=%d", $view));
        if(!$row){ echo '<div class="notice notice-error"><p>'.esc_html__('Registro não encontrado.','pasta-manager').'</p></div>'; echo '</div>'; return; }
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=pasta-acomp-membros')).'">'.esc_html__('‹ Voltar','pasta-manager').'</a></p>';
        echo '<h2 class="title">'.esc_html__('Detalhes do acompanhamento','pasta-manager').'</h2>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>ID</th><td>'.intval($row->id).'</td></tr>';
        echo '<tr><th>'.esc_html__('Data/Hora','pasta-manager').'</th><td>'.esc_html(pm_format_date_br($row->data,true)).'</td></tr>';
        echo '<tr><th>'.esc_html__('Membro','pasta-manager').'</th><td>'.esc_html($row->membro_nome?:'—').'</td></tr>';
        echo '<tr><th>'.esc_html__('Registrado por','pasta-manager').'</th><td>'.esc_html($row->user_name?:'—').'</td></tr>';
        echo '<tr><th>'.esc_html__('Descrição','pasta-manager').'</th><td>'.wp_kses_post(wpautop($row->descricao)).'</td></tr>';
        echo '</tbody></table>';
    }
    echo '</div>';
}

add_action('admin_post_pm_add_acomp', function(){
    if(!current_user_can(PM_CAP_ACOMP_CREATE)) wp_die('Sem permissão');
    check_admin_referer('pm_acomp');
    global $wpdb; $t=$wpdb->prefix.'pasta_acompanhamentos';
    $registro_id=absint($_POST['registro_id'] ?? 0); $data=sanitize_text_field($_POST['data'] ?? ''); $descricao=wp_kses_post(wp_unslash($_POST['descricao'] ?? ''));
    if(!$registro_id){ wp_die('Membro obrigatório.'); }
    if(!$data){ $data=current_time('mysql'); }
    $wpdb->insert($t, [ 'registro_id'=>$registro_id, 'data'=>$data, 'descricao'=>$descricao, 'created_by'=>get_current_user_id(), 'created_at'=>current_time('mysql') ]);
    $new_id=$wpdb->insert_id; pm_log_audit('create','acomp_membro',$new_id,null,['registro_id'=>$registro_id]);
    pm_safe_redirect_fallback( admin_url('admin.php?page=pasta-acomp-membros') );
});

// ---- Configurações: toggle de Membros e de Assets (somente Admin WP) ----
function pm_render_config_page(){
    if(!current_user_can('manage_options')) return;
    if(isset($_POST['pm_members_source']) || isset($_POST['pm_assets_source'])){
        check_admin_referer('pm_config');
        if(isset($_POST['pm_members_source'])){ $val=sanitize_text_field($_POST['pm_members_source']); if(!in_array($val,['local','ajax'],true)) $val='local'; update_option('pm_members_source',$val); }
        if(isset($_POST['pm_assets_source'])){ $as=sanitize_text_field($_POST['pm_assets_source']); if(!in_array($as,['cdn','local'],true)) $as='cdn'; update_option('pm_assets_source',$as); }
        echo '<div class="notice notice-success"><p>Configurações salvas.</p></div>';
    }
    $cur=pm_get_members_source(); $asrc=pm_get_assets_source();
    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Configurações','pasta-manager').'</h1>';
    echo '<form method="post">'; wp_nonce_field('pm_config');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>'.esc_html__('Fonte de membros no "Novo acompanhamento" e "Evolução"','pasta-manager').'</th><td>';
    echo '<label><input type="radio" name="pm_members_source" value="local" '.checked($cur,'local',false).'/> Local (todos os membros carregados no select)</label><br/>';
    echo '<label><input type="radio" name="pm_members_source" value="ajax"  '.checked($cur,'ajax', false).'/> AJAX (busca via Select2 + admin-ajax)</label>';
    echo '</td></tr>';

    echo '<tr><th>'.esc_html__('Origem dos assets (Select2/DataTables)','pasta-manager').'</th><td>';
    echo '<label><input type="radio" name="pm_assets_source" value="cdn" '.checked($asrc,'cdn',false).'/> CDN (recomendado)</label><br/>';
    echo '<label><input type="radio" name="pm_assets_source" value="local" '.checked($asrc,'local',false).'/> Local (arquivos dentro do plugin)</label>';
    echo '<p class="description">Se escolher <strong>Local</strong>, coloque os arquivos oficiais nas pastas <code>assets/vendor/*</code>. O plugin inclui placeholders e exibirá um aviso até que sejam substituídos.</p>';
    echo '</td></tr>';

    echo '</tbody></table>';
    submit_button(__('Salvar configurações','pasta-manager'));
    echo '</form>';
    echo '</div>';
}

function pm_render_evolucao_page(){
    if(!current_user_can(PM_CAP_AUDIT_VIEW)) return;
    global $wpdb; $ta=$wpdb->prefix.'pasta_audit'; $tr=$wpdb->prefix.'pasta_registros'; $tac=$wpdb->prefix.'pasta_acompanhamentos';

    $limit = 500; $mid = isset($_GET['mid']) ? absint($_GET['mid']) : 0; $d_ini = isset($_GET['de']) ? sanitize_text_field($_GET['de']) : ''; $d_fim = isset($_GET['ate']) ? sanitize_text_field($_GET['ate']) : '';

    echo '<div class="wrap">';
    echo '<h1>'.esc_html__('Evolução','pasta-manager').'</h1>';
    echo '<form class="pm-filters" method="get"><input type="hidden" name="page" value="pasta-evolucao" />';
    echo '<label>'.esc_html__('Membro','pasta-manager').' ';
    $source = pm_get_members_source();
    if ($source==='local'){
        $members=$wpdb->get_results("SELECT id, nome FROM $tr ORDER BY nome ASC");
        echo '<select name="mid" class="pm-select2" data-source="local-membros" style="width: 320px"><option value="">'.esc_html__('Todos','pasta-manager').'</option>';
        if($members){ foreach($members as $m){ $sel=selected($mid,$m->id,false); echo '<option value="'.intval($m->id).'" '.$sel.'>'.esc_html($m->nome).'</option>'; } }
        echo '</select></label> ';
    } else {
        echo '<select name="mid" class="pm-select2" data-source="membros" style="width: 320px"><option value="">'.esc_html__('Todos','pasta-manager').'</option></select></label> ';
    }
    echo '<label>'.esc_html__('De','pasta-manager').' <input type="date" name="de" value="'.esc_attr($d_ini).'" /></label> ';
    echo '<label>'.esc_html__('Até','pasta-manager').' <input type="date" name="ate" value="'.esc_attr($d_fim).'" /></label> ';
    submit_button(__('Aplicar','pasta-manager'), 'secondary', '', false);
    if($mid>0||$d_ini||$d_fim){ echo ' <a class="button" href="'.esc_url(admin_url('admin.php?page=pasta-evolucao')).'">'.esc_html__('Limpar','pasta-manager').'</a>'; }
    echo '</form>';

    $items=[];
    $sql_ac = "SELECT a.id, a.data AS when_at, r.nome AS membro, a.descricao AS details, u.display_name AS user_name FROM $tac a LEFT JOIN $tr r ON r.id=a.registro_id LEFT JOIN {$wpdb->users} u ON u.ID=a.created_by";
    $w_ac=[]; $p_ac=[];
    if($mid>0){ $w_ac[]=' a.registro_id = %d '; $p_ac[]=$mid; }
    if($d_ini!==''){ $w_ac[]=' a.data >= %s '; $p_ac[]=$d_ini.' 00:00:00'; }
    if($d_fim!==''){ $w_ac[]=' a.data <= %s '; $p_ac[]=$d_fim.' 23:59:59'; }
    if($w_ac){ $sql_ac.=' WHERE '.implode(' AND ',$w_ac);} $sql_ac.=' ORDER BY a.data DESC LIMIT '.intval($limit);
    $ac=$p_ac?$wpdb->get_results($wpdb->prepare($sql_ac,$p_ac),ARRAY_A):$wpdb->get_results($sql_ac,ARRAY_A);
    foreach($ac as $r){ $items[]=['when_at'=>$r['when_at'],'type'=>'acomp','title'=>'Acompanhamento — '.($r['membro']?:'—'),'desc'=>$r['details'],'user'=>$r['user_name']?:'—']; }

    if($mid>0){
        $sql_m="SELECT a.created_at AS when_at, a.action AS etype, u.display_name AS user_name FROM $ta a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id WHERE a.entity='membro' AND a.entity_id=%d"; $p_m=[$mid];
        if($d_ini!==''){ $sql_m.=' AND a.created_at >= %s'; $p_m[]=$d_ini.' 00:00:00'; }
        if($d_fim!==''){ $sql_m.=' AND a.created_at <= %s'; $p_m[]=$d_fim+' 23:59:59'; }
        $sql_m.=' ORDER BY a.created_at DESC LIMIT '.intval($limit);
        $au_m=$wpdb->get_results($wpdb->prepare($sql_m,$p_m),ARRAY_A);
        foreach($au_m as $r){ $label=($r['etype']==='create'?'Cadastro de membro':($r['etype']==='update'?'Alteração de membro':($r['etype']==='delete'?'Remoção de membro':'Ação em membro'))); $items[]=['when_at'=>$r['when_at'],'type'=>'audit','title'=>$label,'desc'=>'','user'=>$r['user_name']?:'—']; }

        $sql_am="SELECT a.created_at AS when_at, a.action AS etype, u.display_name AS user_name FROM $ta a INNER JOIN $tac t ON a.entity='acomp_membro' AND a.entity_id=t.id LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id WHERE t.registro_id=%d"; $p_am=[$mid];
        if($d_ini!==''){ $sql_am.=' AND a.created_at >= %s'; $p_am[]=$d_ini+' 00:00:00'; }
        if($d_fim!==''){ $sql_am.=' AND a.created_at <= %s'; $p_am[]=$d_fim+' 23:59:59'; }
        $sql_am.=' ORDER BY a.created_at DESC LIMIT '.intval($limit);
        $au_am=$wpdb->get_results($wpdb->prepare($sql_am,$p_am),ARRAY_A);
        foreach($au_am as $r){ $label=($r['etype']==='create'?'Novo acompanhamento (admin)':($r['etype']==='update'?'Edição de acompanhamento':'Ação em acompanhamento')); $items[]=['when_at'=>$r['when_at'],'type'=>'audit','title'=>$label,'desc'=>'','user'=>$r['user_name']?:'—']; }
    } else {
        $sql_au = "SELECT a.created_at AS when_at, a.action AS etype, a.entity, a.entity_id, u.display_name AS user_name FROM $ta a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id ORDER BY a.created_at DESC LIMIT ".intval($limit);
        $au=$wpdb->get_results($sql_au,ARRAY_A);
        foreach($au as $r){ $label='Ação: '.$r['etype'].' em '.$r['entity']; if($r['entity']==='membro'){ $mnome=$wpdb->get_var($wpdb->prepare("SELECT nome FROM $tr WHERE id=%d", (int)$r['entity_id'])); if($r['etype']==='create') $label='Cadastro de membro — '.($mnome?:('#'.$r['entity_id'])); elseif($r['etype']==='update') $label='Alteração de membro — '.($mnome?:('#'.$r['entity_id'])); elseif($r['etype']==='delete') $label='Remoção de membro — '.($mnome?:('#'.$r['entity_id'])); } $items[]=['when_at'=>$r['when_at'],'type'=>'audit','title'=>$label,'desc'=>'','user'=>$r['user_name']?:'—']; }
    }

    usort($items,function($a,$b){ $ta=strtotime($a['when_at']); $tb=strtotime($b['when_at']); if($ta==$tb) return 0; return ($ta>$tb)?-1:1; });

    if(!$items){ echo '<p>'.esc_html__('Sem eventos encontrados.','pasta-manager').'</p>'; echo '</div>'; return; }

    echo '<div class="pm-timeline">';
    foreach($items as $it){
        $badge=( $it['type']==='acomp' ) ? 'Acomp.' : 'Alteração';
        $when=esc_html(pm_format_date_br($it['when_at'],true));
        $title=esc_html($it['title']);
        $desc=$it['desc'] ? wp_kses_post(wpautop($it['desc'])) : '';
        $user=esc_html($it['user']);
        echo '<div class="pm-tl-item">';
        echo '<div class="pm-tl-head"><span class="pm-badge '.($it['type']==='acomp'?'pm-badge-acomp':'pm-badge-audit').'">'.$badge.'</span> '.$when.'</div>';
        echo '<div class="pm-tl-title">'.$title.'</div>';
        echo '<div class="pm-tl-desc">'.$desc.'</div>';
        echo '<div class="pm-tl-user">'.sprintf(esc_html__('Registrado por: %s','pasta-manager'), $user).'</div>';
        echo '</div>';
    }
    echo '</div>';

    echo '</div>';
}
