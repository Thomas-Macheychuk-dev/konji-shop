# Authoritative inputs for the existing live Konji Shop AWS environment.
#
# NOTE:
# AWS resource names/tags still use "staging" because the live infrastructure
# was originally provisioned under that environment name. Renaming is a
# separate migration and must not be mixed with Terraform state recovery.

project_name = "konji-shop"
environment  = "staging"
aws_region   = "eu-central-1"

ssh_cidr_blocks = []
web_cidr_blocks = ["0.0.0.0/0"]
ssh_key_name    = null

instance_type       = "t3.small"
ec2_ami_id          = "ami-042dc8681de073ac4"
root_volume_size_gb = 50

rds_instance_class           = "db.t4g.micro"
rds_allocated_storage_gb     = 20
rds_max_allocated_storage_gb = 100
rds_engine_version           = "8.4.11"
rds_engine_lifecycle_support = "open-source-rds-extended-support-disabled"

db_name     = "konji_shop"
db_username = "konji_shop"

rds_backup_retention_days = 7
rds_deletion_protection   = true
rds_skip_final_snapshot   = false

rds_cloudwatch_log_exports        = ["error", "slowquery"]
rds_managed_cloudwatch_log_groups = ["error"]
cloudwatch_log_retention_days     = 0

s3_bucket_name = "konji-shop-staging-uploads-628263975265"

s3_noncurrent_version_retention_days    = 90
s3_abort_incomplete_multipart_enabled   = false
s3_expired_object_delete_marker_enabled = false

enable_s3_gateway_endpoint      = false
enable_product_media_cloudfront = false

extra_tags = {
  Owner = "konji-shop"
}
