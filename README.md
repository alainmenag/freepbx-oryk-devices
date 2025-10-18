
# get an object with child object of settings
$sql = "
  SELECT 
    d.*, 
    d.tech AS type,
    CONCAT('{', GROUP_CONCAT(CONCAT('\"', s.keyword, '\":\"', s.data, '\"')), '}') AS sip_settings
  FROM devices d
  LEFT JOIN asterisk.sip s ON s.id = d.id
  WHERE d.id = ?
  GROUP BY d.id
";
$stmt = $this->db->prepare($sql);
$stmt->execute([$id]);   // <-- pass $id here
$results = $stmt->fetch(PDO::FETCH_ASSOC);