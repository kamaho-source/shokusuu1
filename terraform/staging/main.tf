terraform {
  required_providers {
    oci = {
      source  = "oracle/oci"
      version = "~> 6.0"
    }
  }
  required_version = ">= 1.5"
}

provider "oci" {
  tenancy_ocid     = var.tenancy_ocid
  user_ocid        = var.user_ocid
  fingerprint      = var.fingerprint
  private_key_path = var.private_key_path
  region           = var.region
}

# ── VCN ──────────────────────────────────────────────────────────────────────

resource "oci_core_vcn" "staging" {
  compartment_id = var.compartment_ocid
  cidr_block     = var.vcn_cidr
  display_name   = "staging-vcn"
  dns_label      = "staging"
}

# ── インターネットゲートウェイ ────────────────────────────────────────────────

resource "oci_core_internet_gateway" "staging" {
  compartment_id = var.compartment_ocid
  vcn_id         = oci_core_vcn.staging.id
  display_name   = "staging-igw"
  enabled        = true
}

# ── ルートテーブル ──────────────────────────────────────────────────────────

resource "oci_core_route_table" "staging" {
  compartment_id = var.compartment_ocid
  vcn_id         = oci_core_vcn.staging.id
  display_name   = "staging-route-table"

  route_rules {
    destination       = "0.0.0.0/0"
    destination_type  = "CIDR_BLOCK"
    network_entity_id = oci_core_internet_gateway.staging.id
  }
}

# ── セキュリティリスト ──────────────────────────────────────────────────────

resource "oci_core_security_list" "staging" {
  compartment_id = var.compartment_ocid
  vcn_id         = oci_core_vcn.staging.id
  display_name   = "staging-security-list"

  # インバウンド: SSH
  ingress_security_rules {
    protocol  = "6" # TCP
    source    = "0.0.0.0/0"
    stateless = false
    tcp_options {
      min = 22
      max = 22
    }
  }

  # インバウンド: HTTP
  ingress_security_rules {
    protocol  = "6"
    source    = "0.0.0.0/0"
    stateless = false
    tcp_options {
      min = 80
      max = 80
    }
  }

  # インバウンド: HTTPS
  ingress_security_rules {
    protocol  = "6"
    source    = "0.0.0.0/0"
    stateless = false
    tcp_options {
      min = 443
      max = 443
    }
  }

  # アウトバウンド: 全通信許可
  egress_security_rules {
    protocol    = "all"
    destination = "0.0.0.0/0"
    stateless   = false
  }
}

# ── パブリックサブネット ────────────────────────────────────────────────────

resource "oci_core_subnet" "staging_public" {
  compartment_id    = var.compartment_ocid
  vcn_id            = oci_core_vcn.staging.id
  cidr_block        = var.subnet_cidr
  display_name      = "staging-public-subnet"
  dns_label         = "public"
  route_table_id    = oci_core_route_table.staging.id
  security_list_ids = [oci_core_security_list.staging.id]

  prohibit_public_ip_on_vnic = false
}

# ── イメージ (Ubuntu 22.04 AMD x86_64 / Always Free: VM.Standard.E2.1.Micro) ─

data "oci_core_images" "ubuntu" {
  compartment_id           = var.compartment_ocid
  operating_system         = "Canonical Ubuntu"
  operating_system_version = "22.04"
  shape                    = var.instance_shape
  sort_by                  = "TIMECREATED"
  sort_order               = "DESC"
}

# ── Computeインスタンス (Always Free: VM.Standard.E2.1.Micro) ─────────────

resource "oci_core_instance" "staging" {
  compartment_id      = var.compartment_ocid
  availability_domain = var.availability_domain
  display_name        = "staging-server"
  shape               = var.instance_shape

  source_details {
    source_type             = "image"
    source_id               = data.oci_core_images.ubuntu.images[0].id
    boot_volume_size_in_gbs = 50
  }

  create_vnic_details {
    subnet_id        = oci_core_subnet.staging_public.id
    assign_public_ip = true
    display_name     = "staging-vnic"
  }

  metadata = {
    ssh_authorized_keys = var.ssh_public_key
    user_data = base64encode(<<-EOF
      #!/bin/bash
      apt-get update -y
      apt-get install -y nginx php8.1-fpm php8.1-mysql php8.1-mbstring php8.1-xml php8.1-zip git composer certbot python3-certbot-nginx
      systemctl enable nginx php8.1-fpm
      systemctl start nginx php8.1-fpm

      # Nginxのステージング設定
      cat > /etc/nginx/sites-available/staging <<'NGINX'
      server {
          listen 80;
          server_name ${var.staging_domain};
          root /var/www/html/current/webroot;
          index index.php;

          location / {
              try_files $uri $uri/ /index.php?$args;
          }

          location ~ \.php$ {
              fastcgi_pass unix:/run/php/php8.1-fpm.sock;
              fastcgi_index index.php;
              include fastcgi_params;
              fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
          }
      }
      NGINX

      ln -sf /etc/nginx/sites-available/staging /etc/nginx/sites-enabled/staging
      rm -f /etc/nginx/sites-enabled/default
      mkdir -p /var/www/html/current/webroot
      systemctl reload nginx
    EOF
    )
  }

  timeouts {
    create = "10m"
  }
}
