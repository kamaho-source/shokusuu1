variable "tenancy_ocid" {
  description = "OCIテナンシーのOCID"
  type        = string
}

variable "user_ocid" {
  description = "OCIユーザーのOCID"
  type        = string
}

variable "fingerprint" {
  description = "APIキーのフィンガープリント"
  type        = string
}

variable "private_key_path" {
  description = "OCI APIキー秘密鍵のパス"
  type        = string
  default     = "~/.oci/oci_api_key.pem"
}

variable "region" {
  description = "OCIリージョン"
  type        = string
  default     = "ap-tokyo-1"
}

variable "availability_domain" {
  description = "可用性ドメイン名"
  type        = string
  default     = "Osib:AP-TOKYO-1-AD-1"
}

variable "compartment_ocid" {
  description = "コンパートメントのOCID（未指定時はルートテナンシー）"
  type        = string
}

variable "vcn_cidr" {
  description = "VCNのCIDRブロック"
  type        = string
  default     = "10.0.0.0/16"
}

variable "subnet_cidr" {
  description = "サブネットのCIDRブロック"
  type        = string
  default     = "10.0.1.0/24"
}

variable "instance_shape" {
  description = "コンピュートインスタンスのシェイプ（Always Free: VM.Standard.E2.1.Micro または VM.Standard.A1.Flex）"
  type        = string
  default     = "VM.Standard.E2.1.Micro"
}

variable "staging_domain" {
  description = "ステージング環境のドメイン名"
  type        = string
  default     = "stg.kamaho-shokusu.jp"
}

variable "ssh_public_key" {
  description = "インスタンスへのSSHアクセス用公開鍵"
  type        = string
}
