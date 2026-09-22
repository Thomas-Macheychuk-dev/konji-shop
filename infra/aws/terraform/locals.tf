locals {
  name_prefix = "${var.project_name}-${var.environment}"

  rds_identifier = "${local.name_prefix}-mysql"

  common_tags = merge(
    {
      Project     = var.project_name
      Environment = var.environment
      ManagedBy   = "terraform"
      Application = "konji-shop"
    },
    var.extra_tags,
  )
}
