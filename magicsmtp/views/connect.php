<?php declare(strict_types=1); defined('MW_PATH') or exit('No direct script access allowed');
$escape=static function($v):string{return CHtml::encode((string)$v);};
$csrf=static function()use($escape):void{echo '<input type="hidden" name="'.$escape(request()->csrfTokenName).'" value="'.$escape(request()->csrfToken).'">';};
?>
<div class="box box-primary borderless">
 <div class="box-header"><h3 class="box-title">Connect MailWizz to MagicSMTP</h3></div>
 <div class="box-body">
  <p>Use one secure connection for your eligible delivery servers. Existing campaign settings and sending limits stay in place.</p>
  <?php if($error): ?><div class="alert alert-danger" role="alert"><?php echo $escape($error); ?></div><?php endif; ?>
  <?php if($backend): ?>
   <form method="get" class="form-inline"><label for="connect-customer">Customer ID</label> <input id="connect-customer" class="form-control" type="number" min="1" name="customer_id" value="<?php echo $customerId?:''; ?>" required> <button class="btn btn-primary" type="submit">View customer</button></form>
   <hr><form method="post"><?php $csrf(); ?><input type="hidden" name="customer_id" value="<?php echo (int)$customerId; ?>"><input type="hidden" name="operation" value="enable"><button class="btn btn-default" type="submit">Enable customer connections</button><p class="help-block">One-time installation approval. The protected encryption key and verified scheduler profile must already be installed.</p></form>
  <?php endif; ?>
  <?php if($page): ?>
   <?php if($page['demo']): ?><div class="alert alert-info">Demonstration workspace. Connection changes are saved here; no external service or real sending credential is used.</div><?php elseif(!$page['enabled']): ?><div class="alert alert-warning">An installation administrator needs to enable secure connections before you can pair this account.</div><?php endif; ?>
   <h4>Delivery servers</h4>
   <div class="table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>Server</th><th>Status</th><th>Connection</th></tr></thead><tbody>
   <?php foreach($page['servers']as$server): ?><tr><td><?php echo $escape($server['name']); ?> <span class="text-muted">#<?php echo (int)$server['id']; ?></span></td><td><?php echo $escape($server['status']); ?></td><td><?php echo $server['connected']?'Included in existing binding':($server['eligible']?'Available to connect':'Not eligible for this connection'); ?></td></tr><?php endforeach; ?>
   <?php if(!$page['servers']): ?><tr><td colspan="3">No eligible delivery servers are available for this customer.</td></tr><?php endif; ?>
   </tbody></table></div>
   <?php if($page['enabled']): ?>
    <form method="post"><?php $csrf(); ?><?php if($backend): ?><input type="hidden" name="customer_id" value="<?php echo (int)$customerId; ?>"><?php endif; ?><input type="hidden" name="operation" value="generate">
     <?php if(!$page['demo']): ?><div class="form-group"><label for="connect-endpoint">Callback delivery server</label><select class="form-control" name="endpoint_id" id="connect-endpoint" required><option value="">Choose a server</option><?php foreach($page['servers']as$server):if(empty($server['codeEndpoint']))continue; ?><option value="<?php echo (int)$server['id']; ?>"><?php echo $escape($server['name']); ?> (#<?php echo (int)$server['id']; ?>)</option><?php endforeach; ?></select></div><?php endif; ?>
     <button class="btn btn-primary" type="submit">Create pairing code</button><p class="help-block">Paste the code into Connect MailWizz in your MagicSMTP tenant account. It expires after ten minutes and can pair one connection.</p>
    </form>
   <?php endif; ?>
   <?php if($pairing): ?><div class="form-group"><label for="connect-code">Pairing code</label><textarea id="connect-code" class="form-control" rows="4" readonly autocomplete="off" spellcheck="false"><?php echo $escape($pairing['pairingCode']); ?></textarea><p class="help-block">For <?php echo $escape($pairing['tenantName']); ?>. Expires <?php echo $escape(gmdate('H:i \U\T\C',$pairing['expiresAt'])); ?>. Treat this code as private.</p></div><?php endif; ?>
   <?php foreach($page['connections']as$connection): ?><div class="well"><strong><?php echo $escape($connection['tenantName']); ?></strong><p><?php echo $escape($connection['state']==='active'?'Connected':'Pairing saved; finish selecting servers in MagicSMTP.'); ?> · Revision <?php echo (int)$connection['revision']; ?></p><p>Servers: <?php echo $escape(implode(', ',$connection['serverIds'])); ?></p></div><?php endforeach; ?>
   <?php if($page['demo']): ?><hr><form method="post"><?php $csrf(); ?><?php if($backend): ?><input type="hidden" name="customer_id" value="<?php echo (int)$customerId; ?>"><?php endif; ?><input type="hidden" name="operation" value="demo-save"><div class="form-group"><label for="connect-demo-label">Connection name</label><input id="connect-demo-label" class="form-control" name="label" maxlength="100" value="<?php echo $escape($page['demoState']['label']??'Sample newsletter connection'); ?>" required></div><button class="btn btn-primary" type="submit">Save sample connection</button></form><?php endif; ?>
  <?php endif; ?>
 </div>
</div>
