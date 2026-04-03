output "instance_public_ip" {
  description = "ステージングサーバーのパブリックIPアドレス"
  value       = oci_core_instance.staging.public_ip
}

output "instance_id" {
  description = "コンピュートインスタンスのOCID"
  value       = oci_core_instance.staging.id
}

output "vcn_id" {
  description = "VCNのOCID"
  value       = oci_core_vcn.staging.id
}

output "subnet_id" {
  description = "パブリックサブネットのOCID"
  value       = oci_core_subnet.staging_public.id
}

output "ssh_command" {
  description = "SSH接続コマンド"
  value       = "ssh -i ~/.ssh/staging_key ubuntu@${oci_core_instance.staging.public_ip}"
}

output "staging_url" {
  description = "ステージング環境URL"
  value       = "https://${var.staging_domain}"
}

output "dns_a_record" {
  description = "DNSレジストラで設定するAレコード"
  value       = "stg.kamaho-shokusu.jp → ${oci_core_instance.staging.public_ip}"
}
