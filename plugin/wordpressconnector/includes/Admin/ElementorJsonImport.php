<?php

declare(strict_types=1);
namespace Webactueel\WordPressConnector\Admin;
use RuntimeException; use Throwable; use Webactueel\WordPressConnector\Adapters\ElementorAdapter;
final class ElementorJsonImport
{
    private const BULK_PREVIEW='wpconnector_elementor_bulk_preview'; private const BULK_CONFIRM='wpconnector_elementor_bulk_confirm'; private const BULK_LIMIT=20; private const BULK_BYTES=26214400; private const ACTION='wpconnector_import_elementor_json'; private const POST_TYPES=array('page','post','elementor_library'); private const MAX_BYTES=5242880;
    public function register(): void{if(!is_admin()){return;}add_filter('page_row_actions',array($this,'rowActions'),99,2);add_filter('post_row_actions',array($this,'rowActions'),99,2);add_action('admin_menu',array($this,'registerImportPages'));add_action('admin_post_'.self::ACTION,array($this,'handleImport'));add_action('admin_post_'.self::BULK_PREVIEW,array($this,'previewBulk'));add_action('admin_post_'.self::BULK_CONFIRM,array($this,'confirmBulk'));}
    public function rowActions(array $actions,\WP_Post $post): array{if(!$this->supportsPostType((string)$post->post_type)||!current_user_can('edit_post',(int)$post->ID)){return $actions;}$actions['wpconnector_import_elementor_json']=sprintf('<a href="%1$s">%2$s</a>',esc_url($this->importUrl((string)$post->post_type,(int)$post->ID)),esc_html__('Import Elementor JSON','wordpressconnector'));return $actions;}
    public function registerImportPages(): void{add_management_page(__('Elementor bulkimport','wordpressconnector'),__('Elementor bulkimport','wordpressconnector'),'manage_options','wpconnector-elementor-bulk',array($this,'renderBulkPage'));add_submenu_page('edit.php?post_type=page',__('Import Elementor JSON','wordpressconnector'),__('Import Elementor JSON','wordpressconnector'),'edit_pages','wpconnector-import-elementor-page',function():void{$this->renderImportPage('page');});add_submenu_page('edit.php',__('Import Elementor JSON','wordpressconnector'),__('Import Elementor JSON','wordpressconnector'),'edit_posts','wpconnector-import-elementor-post',function():void{$this->renderImportPage('post');});if(post_type_exists('elementor_library')){add_submenu_page('edit.php?post_type=elementor_library',__('Import Elementor JSON','wordpressconnector'),__('Import Elementor JSON','wordpressconnector'),'edit_posts','wpconnector-import-elementor-template',function():void{$this->renderImportPage('elementor_library');});}}
    public function renderImportPage(string $postType): void
    {
        if(!$this->supportsPostType($postType)||!current_user_can($this->capabilityForPostType($postType))){wp_die(esc_html__('You are not allowed to import Elementor JSON here.','wordpressconnector'),'',array('response'=>403));}$selectedId=isset($_GET['post_id'])?absint(wp_unslash($_GET['post_id'])):0;$posts=get_posts(array('post_type'=>$postType,'post_status'=>array('publish','draft','pending','private','future'),'posts_per_page'=>200,'orderby'=>'modified','order'=>'DESC'));
        echo '<div class="wrap"><h1>'.esc_html__('Import Elementor JSON','wordpressconnector').'</h1><p>'.esc_html__('Upload an Elementor JSON document. Choose an existing item to replace only its Elementor structure/settings, or choose Create new to make a new draft. Existing-item writes use Elementor readback and automatic rollback.','wordpressconnector').'</p><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field(self::ACTION);echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'"><input type="hidden" name="post_type" value="'.esc_attr($postType).'"><table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="wpconnector-target-post">'.esc_html__('Target','wordpressconnector').'</label></th><td><select id="wpconnector-target-post" name="post_id">';printf('<option value="0"%1$s>%2$s</option>',selected($selectedId,0,false),esc_html__('Create new draft from JSON','wordpressconnector'));foreach($posts as $post){if(!$post instanceof \WP_Post||!current_user_can('edit_post',(int)$post->ID)){continue;}printf('<option value="%1$d"%2$s>%3$s (#%1$d)</option>',(int)$post->ID,selected($selectedId,(int)$post->ID,false),esc_html((string)$post->post_title));}echo '</select></td></tr><tr><th scope="row"><label for="wpconnector-elementor-json">'.esc_html__('Elementor JSON file','wordpressconnector').'</label></th><td><input id="wpconnector-elementor-json" type="file" name="elementor_json" accept="application/json,.json" required><p class="description">'.esc_html__('Accepted fields: content, page_settings (or settings), version, title, type, global_classes and global_variables. Maximum file size: 5 MB.','wordpressconnector').'</p></td></tr></tbody></table>';submit_button(__('Import Elementor JSON','wordpressconnector'));echo '</form></div>';
    }
    public function handleImport(): void
    {
        check_admin_referer(self::ACTION);$postId=isset($_POST['post_id'])?absint(wp_unslash($_POST['post_id'])):0;$postType=isset($_POST['post_type'])?sanitize_key(wp_unslash($_POST['post_type'])):'';if(!$this->supportsPostType($postType)||!current_user_can($this->capabilityForPostType($postType))){wp_die(esc_html__('Invalid Elementor JSON import target.','wordpressconnector'),'',array('response'=>400));}
        try{$payload=$this->uploadedPayload();$data=$payload['content'];$settings=isset($payload['page_settings'])&&is_array($payload['page_settings'])?$payload['page_settings']:(isset($payload['settings'])&&is_array($payload['settings'])?$payload['settings']:array());$adapter=new ElementorAdapter();if($postId>0){$post=get_post($postId);if(!$post instanceof \WP_Post||(string)$post->post_type!==$postType){throw new RuntimeException('The selected import target no longer exists.');}if(!current_user_can('edit_post',$postId)){wp_die(esc_html__('You are not allowed to edit this item.','wordpressconnector'),'',array('response'=>403));}$adapterPayload=array('id'=>$postId,'data'=>$data,'page_settings'=>$settings,'edit_mode'=>'builder');if('elementor_library'===$postType){$adapterPayload['template_type']=$this->templateType($payload);}$adapter->replaceDocument($adapterPayload,array('dry_run'=>false));$resultId=$postId;}else{$adapterPayload=array('post_type'=>$postType,'status'=>'draft','title'=>$this->importTitle($payload,$postType),'data'=>$data,'page_settings'=>$settings,'edit_mode'=>'builder');if('elementor_library'===$postType){$adapterPayload['template_type']=$this->templateType($payload);}$created=$adapter->createDocument($adapterPayload,array('dry_run'=>false));$resultId=isset($created['document']['post_id'])?(int)$created['document']['post_id']:0;if($resultId<1){throw new RuntimeException('Elementor JSON was imported but the new document ID could not be verified.');}}}catch(RuntimeException $error){wp_die(esc_html($error->getMessage()),'',array('response'=>400));}catch(Throwable $error){wp_die(esc_html__('The Elementor JSON import failed.','wordpressconnector'),'',array('response'=>500));}
        wp_safe_redirect(add_query_arg(array('post_type'=>$postType,'wpconnector_elementor_imported'=>1,'post'=>$resultId),admin_url('edit.php')));exit;
    }
    private function uploadedPayload(): array
    {
        if(!isset($_FILES['elementor_json'])||!is_array($_FILES['elementor_json'])){throw new RuntimeException('No Elementor JSON file was uploaded.');}$file=$_FILES['elementor_json'];$error=isset($file['error'])?(int)$file['error']:UPLOAD_ERR_NO_FILE;if(UPLOAD_ERR_OK!==$error){throw new RuntimeException('The Elementor JSON upload failed.');}$size=isset($file['size'])?(int)$file['size']:0;if($size<1||$size>self::MAX_BYTES){throw new RuntimeException('The Elementor JSON file must be between 1 byte and 5 MB.');}$name=isset($file['name'])?sanitize_file_name((string)$file['name']):'';if('json'!==strtolower((string)pathinfo($name,PATHINFO_EXTENSION))){throw new RuntimeException('Choose a .json Elementor export file.');}$tmpName=isset($file['tmp_name'])?(string)$file['tmp_name']:'';if(''===$tmpName||!is_uploaded_file($tmpName)){throw new RuntimeException('The Elementor JSON upload is invalid.');}$raw=file_get_contents($tmpName);if(!is_string($raw)||''===trim($raw)||strlen($raw)>self::MAX_BYTES){throw new RuntimeException('The Elementor JSON file is empty or too large.');}$decoded=json_decode($raw,true);if(!is_array($decoded)||JSON_ERROR_NONE!==json_last_error()){throw new RuntimeException('The uploaded file is not valid JSON.');}if(isset($decoded['format'])&&'wordpressconnector/elementor-site-parts-bundle'===$decoded['format']){if(!isset($decoded['document'])||!is_array($decoded['document'])){throw new RuntimeException('The Elementor site-parts bundle does not contain a document payload.');}$decoded=$decoded['document'];}if(!isset($decoded['content'])||!is_array($decoded['content'])||empty($decoded['content'])){throw new RuntimeException('The JSON is not a non-empty Elementor document export.');}return $decoded;
    }

    public function renderBulkPage(): void
    {
        if(!current_user_can('manage_options')){wp_die('Not allowed.', '', array('response'=>403));}
        echo '<div class="wrap"><h1>Elementor JSON bulkimport</h1><p>Upload maximaal 20 losse Elementor JSON-bestanden. Elk bestand wordt alleen gekoppeld aan een <strong>unieke bestaande slug in het juiste berichttype</strong>. Er worden geen pagina’s op titelgelijkheid gekozen en er worden geen nieuwe berichten gemaakt. Eerst controleren, daarna afzonderlijk bevestigen.</p>';
        echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post" enctype="multipart/form-data">';
        wp_nonce_field(self::BULK_PREVIEW);
        echo '<input type="hidden" name="action" value="'.esc_attr(self::BULK_PREVIEW).'">';
        echo '<p><input type="file" name="elementor_jsons[]" accept=".json,application/json" multiple required></p>';
        submit_button('Controleer bestanden en doelpagina’s');
        echo '</form></div>';
    }

    public function previewBulk(): void
    {
        check_admin_referer(self::BULK_PREVIEW);
        if(!current_user_can('manage_options')){wp_die('Not allowed.', '', array('response'=>403));}
        try{
            $entries=$this->bulkUploads();
            $plan=$this->buildBulkPlan($entries);
            $allOk=true;
            foreach($plan as $row){if('ready'!==$row['status']){$allOk=false;}}
            $token=wp_generate_password(48,false,false);
            // WordPress private transient; never return uploaded JSON in page HTML.
            set_transient('wpconnector_bulk_'.get_current_user_id().'_'.hash('sha256',$token),array('plan'=>$plan,'created'=>time()),10*MINUTE_IN_SECONDS);
            echo '<div class="wrap"><h1>Bulkimport controleren</h1><p>Alleen de hieronder getoonde bestaande Elementor-documenten kunnen worden overschreven. Bij een conflict wordt de hele batch geblokkeerd.</p><table class="widefat striped"><thead><tr><th>Bestand</th><th>Berichttype</th><th>Doel</th><th>Status</th></tr></thead><tbody>';
            foreach($plan as $row){echo '<tr><td>'.esc_html($row['name']).'</td><td>'.esc_html($row['post_type']).'</td><td>'.esc_html($row['target_label']).'</td><td>'.esc_html($row['status']).'</td></tr>';}
            echo '</tbody></table>';
            if($allOk){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field(self::BULK_CONFIRM);echo '<input type="hidden" name="action" value="'.esc_attr(self::BULK_CONFIRM).'"><input type="hidden" name="token" value="'.esc_attr($token).'">';echo '<p><label><input type="checkbox" name="confirmed" value="yes" required> Ik heb alle doel-ID’s gecontroleerd en geef toestemming om hun Elementor-inhoud te overschrijven.</label></p>';submit_button('Bevestig overschrijven van '.count($plan).' documenten','primary');echo '</form>';}
            else{echo '<p><strong>Batch geblokkeerd:</strong> los alle conflicten op en upload opnieuw.</p>';}
            echo '</div>';
        }catch(Throwable $error){wp_die(esc_html($error->getMessage()),'',array('response'=>400));}
    }

    public function confirmBulk(): void
    {
        check_admin_referer(self::BULK_CONFIRM);
        if(!current_user_can('manage_options')){wp_die('Not allowed.', '', array('response'=>403));}
        $token=isset($_POST['token'])&&is_string($_POST['token'])?sanitize_text_field(wp_unslash($_POST['token'])):'';
        if(!preg_match('/^[A-Za-z0-9]{48}$/D',$token)||!isset($_POST['confirmed'])||'yes'!==$_POST['confirmed']){wp_die('Bulkimport not confirmed.','',array('response'=>400));}
        $key='wpconnector_bulk_'.get_current_user_id().'_'.hash('sha256',$token);
        $state=get_transient($key);
        if(!is_array($state)||!isset($state['plan'])||!is_array($state['plan'])){wp_die('Preview expired. Upload again.','',array('response'=>409));}
        delete_transient($key); // Single-use confirmation: no replay.
        $plan=$state['plan'];
        if(count($plan)<1||count($plan)>self::BULK_LIMIT){wp_die('Invalid plan.','',array('response'=>400));}
        try{
            // Re-check every target and every snapshot before making the first write.
            foreach($plan as $row){
                if('ready'!==$row['status']||!current_user_can('edit_post',(int)$row['post_id'])){throw new RuntimeException('Target permission changed. No documents updated.');}
                $post=get_post((int)$row['post_id']);
                if(!$post instanceof \WP_Post||(string)$post->post_type!==$row['post_type']||(string)$post->post_name!==$row['slug']){throw new RuntimeException('Target changed since preview. No documents updated.');}
                $current=(new ElementorAdapter())->inspect(array('id'=>(int)$row['post_id']));
                if(!hash_equals($row['fingerprint'],(string)($current['fingerprint']??''))){throw new RuntimeException('Document changed since preview. No documents updated.');}
            }
            // A separate Elementor rollback snapshot is captured by each replaceDocument call.
            // Do not claim cross-document atomicity: stop immediately on a failed write.
            $updated=array();
            foreach($plan as $row){
                $payload=array('id'=>(int)$row['post_id'],'data'=>$row['content'],'page_settings'=>$row['settings'],'edit_mode'=>'builder');
                if('elementor_library'===$row['post_type']){$payload['template_type']=$row['template_type'];}
                (new ElementorAdapter())->replaceDocument($payload,array('dry_run'=>false));
                $updated[]=$row['post_id'];
            }
            echo '<div class="wrap"><h1>Elementor bulkimport uitgevoerd</h1><p>'.count($updated).' documenten opgeslagen en door de Connector teruggelezen.</p><p>Controleer na import handmatig de frontend, formulieren en responsieve lay-out.</p></div>';
        }catch(Throwable $error){wp_die('Bulkimport gestopt: '.esc_html($error->getMessage()).' Reeds geslaagde eerdere documenten zijn niet automatisch teruggezet; controleer de WordPress-revisies en back-up.','',array('response'=>409));}
    }

    private function bulkUploads(): array
    {
        $files=$_FILES['elementor_jsons']??null;
        if(!is_array($files)||!isset($files['name'])||!is_array($files['name'])){throw new RuntimeException('No files uploaded.');}
        $count=count($files['name']);
        if($count<1||$count>self::BULK_LIMIT){throw new RuntimeException('Upload 1 to 20 JSON files.');}
        $total=0;$entries=array();
        for($i=0;$i<$count;$i++){
            if((int)($files['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){throw new RuntimeException('One of the uploads failed.');}
            $name=sanitize_file_name((string)($files['name'][$i]??''));
            $size=(int)($files['size'][$i]??0);$total+=$size;
            if(strtolower((string)pathinfo($name,PATHINFO_EXTENSION))!=='json'||$size<1||$size>self::MAX_BYTES||$total>self::BULK_BYTES){throw new RuntimeException('Only JSON files up to 5 MB each and 25 MB in total are allowed.');}
            $tmp=(string)($files['tmp_name'][$i]??'');
            if(!is_uploaded_file($tmp)){throw new RuntimeException('Invalid uploaded file.');}
            $raw=file_get_contents($tmp);if(!is_string($raw)||strlen($raw)>self::MAX_BYTES){throw new RuntimeException('Could not read upload.');}
            $payload=json_decode($raw,true);
            if(!is_array($payload)||json_last_error()!==JSON_ERROR_NONE){throw new RuntimeException('Invalid JSON in '.$name);}
            if(isset($payload['format'])&&'wordpressconnector/elementor-site-parts-bundle'===$payload['format']){$payload=$payload['document']??array();}
            if(!isset($payload['content'])||!is_array($payload['content'])||empty($payload['content'])){throw new RuntimeException('Missing Elementor content in '.$name);}
            $entries[]=array('name'=>$name,'payload'=>$payload);
        }
        return $entries;
    }

    private function buildBulkPlan(array $entries): array
    {
        $plan=array();$seenTargets=array();
        foreach($entries as $entry){
            $name=$entry['name'];$payload=$entry['payload'];
            $source=isset($payload['source'])&&is_array($payload['source'])?$payload['source']:array();
            $declaredType=sanitize_key((string)($source['post_type']??''));
            $documentType=sanitize_key((string)($payload['type']??''));
            $postType=$declaredType;
            if(''===$postType){$postType='wp-post'===$documentType?'post':('wp-page'===$documentType||'page'===$documentType?'page':'');}
            if(''===$postType||!post_type_exists($postType)||!is_post_type_viewable($postType)&&'elementor_library'!==$postType){
                $postType=''; // Unknown type: never guess.
            }
            $slug=isset($source['slug'])?sanitize_title((string)$source['slug']):'';
            if(''===$slug){$slug=preg_replace('/-elementor(?:-with-site-parts)?\\.json$/i','',$name);$slug=sanitize_title((string)$slug);}
            $row=array('name'=>$name,'post_type'=>$postType,'slug'=>$slug,'post_id'=>0,'target_label'=>'-', 'status'=>'conflict: unknown type or target','content'=>$payload['content'],'settings'=>isset($payload['page_settings'])&&is_array($payload['page_settings'])?$payload['page_settings']:(isset($payload['settings'])&&is_array($payload['settings'])?$payload['settings']:array()),'template_type'=>$documentType,'fingerprint'=>'');
            if(''!==$postType&&''!==$slug){
                $matches=get_posts(array('post_type'=>$postType,'post_status'=>'any','name'=>$slug,'posts_per_page'=>3,'fields'=>'ids','suppress_filters'=>true));
                if(count($matches)===1){
                    $id=(int)$matches[0];$post=get_post($id);
                    if($post instanceof \WP_Post&&(string)$post->post_name===$slug&&current_user_can('edit_post',$id)){
                        $row['post_id']=$id;$row['target_label']=(string)$post->post_title.' (#'.$id.')';
                        try{$inspection=(new ElementorAdapter())->inspect(array('id'=>$id));$row['fingerprint']=(string)$inspection['fingerprint'];$row['status']='ready';}catch(Throwable $e){$row['status']='conflict: Elementor read unavailable';}
                    }else{$row['status']='conflict: insufficient permission';}
                }elseif(count($matches)>1){$row['status']='conflict: duplicate slug';}
                else{$row['status']='conflict: no exact existing slug';}
            }
            if($row['post_id']>0){if(isset($seenTargets[$row['post_id']])){$row['status']='conflict: repeated target';$plan[$seenTargets[$row['post_id']]]['status']='conflict: repeated target';}else{$seenTargets[$row['post_id']]=count($plan);}}
            $plan[]=$row;
        }
        return $plan;
    }

    private function importTitle(array $payload,string $postType): string{$title=isset($payload['title'])&&is_string($payload['title'])?sanitize_text_field($payload['title']):'';return ''!==$title?$title:('elementor_library'===$postType?'Imported Elementor Template':'Imported Elementor '.ucfirst($postType));} private function templateType(array $payload): string{$type=isset($payload['type'])&&is_string($payload['type'])?sanitize_key($payload['type']):'';return ''!==$type?$type:'page';} private function importUrl(string $postType,int $postId): string{$slug='page'===$postType?'wpconnector-import-elementor-page':('post'===$postType?'wpconnector-import-elementor-post':'wpconnector-import-elementor-template');$base='post'===$postType?admin_url('edit.php'):admin_url('edit.php?post_type='.$postType);return add_query_arg(array('page'=>$slug,'post_id'=>$postId),$base);} private function capabilityForPostType(string $postType): string{return 'page'===$postType?'edit_pages':'edit_posts';} private function supportsPostType(string $postType): bool{return in_array($postType,self::POST_TYPES,true);}
}
