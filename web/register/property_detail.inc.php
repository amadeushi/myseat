
				<h3>	 	 				 
					<img class="property-logo" src="../uploads/logo/<?php echo ($row['logo_filename']=='') ? 'logo.png' : $row['logo_filename'];?>" border='0'>		
					<?php echo $row['name'];?>
				</h3>
				<br class="cl" />
				<br/>
				<img class="property-image" src="../uploads/img/<?php echo ($row['img_filename']=='') ? 'noImage.png' : $row['img_filename'];?>" border='0'>
				<br class="cl" />
				<br/><br/>
				<label><?php echo _contact;?></label>
				<p><strong>
					<?php echo $row['contactperson'];?>
				</strong></p>
				<label><?php echo _adress;?></label>
				<p><strong>
					<?php echo $row['street'];?>
				</strong></p>
				<label><?php echo _zip;?></label>
				<p><strong>
					<?php echo $row['zip'];?>
				</strong></p>
				<label><?php echo _city;?></label>
				<p><strong>
					<?php echo $row['city'];?>
				</strong></p>
				<label><?php echo _country;?></label>
				<p><strong>
					<?php echo $countries[$row['country']];?>
				</strong></p>
				<label><?php echo _email;?></label>
				<p><strong>
					<?php echo $row['email'];?>
				</strong></p>
				<label><?php echo _website;?></label>
				<p><strong>
					<?php echo $row['website'];?>
				</strong></p>
				<label><?php echo _phone;?></label>
				<p><strong>		 	 	 	 	 	 	
					<?php echo $row['phone'];?>
				</strong></p>
<?php if (!empty($row['fax'])) { ?>
				<label><?php echo _fax;?></label>	
				<p>	 	 	 	 	 	 	
					<?php echo $row['fax'];?>
				</p>
<?php
}
if (!empty($row['social_fb'])) { ?>
				<label>Facebook Link</label>	
				<p>	 	 	 	 	 	 	
					<?php echo $row['social_fb'];?>
				</p>
<?php
}
if (!empty($row['social_tw'])) { ?>
				<label>Twitter Link</label>	
				<p>	 	 	 	 	 	 	
					<?php echo $row['social_tw'];?>
				</p>
<?php } ?>
				<br/><br/>	 	 	 	 	 	 	 
				<small>				
					<?php if($row['created']){ echo _created." ".humanize($row['created']);}?>
				</small>
