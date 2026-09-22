resource "aws_cloudwatch_log_group" "rds" {
  for_each = toset(var.rds_managed_cloudwatch_log_groups)

  name              = "/aws/rds/instance/${local.rds_identifier}/${each.value}"
  retention_in_days = var.cloudwatch_log_retention_days

  tags = {
    Name = "${local.name_prefix}-rds-${each.value}"
  }

  lifecycle {
    ignore_changes = [
      tags,
      tags_all,
    ]
  }
}
