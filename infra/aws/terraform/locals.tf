locals {
  name_prefix = "${var.project_name}-${var.environment}"

  db_password = var.db_password != null && var.db_password != "" ? var.db_password : random_password.db_password.result

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
