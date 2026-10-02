<?php
$conn = new mysqli("localhost","root","","pt_bintang_perkasa_sejati");
if(isset($_POST['upload'])){
 $j=$_POST['judul']; $f=$_FILES['file']['name']; move_uploaded_file($_FILES['file']['tmp_name'],"uploads/".$f);
 $conn->query("INSERT INTO reports (title, file_name) VALUES ('$j','$f')");
 echo "<script>alert('File Berhasil Disimpan!')</script>";
}
$q=$conn->query("SELECT * FROM reports ORDER BY id DESC");
?>
<h2>PT BINTANG PERKASA SEJATI - File Storage</h2>
<form method="post" enctype="multipart/form-data">
<input type="text" name="judul" placeholder="Judul File" required>
<input type="file" name="file" required>
<button name="upload">SIMPAN</button>
</form><hr>
<?php while($r=$q->fetch_assoc()){ echo "📄 <b>".$r['title']."</b> - <a href='uploads/".$r['file_name']."' target='_blank'>Lihat</a><br>"; } ?>