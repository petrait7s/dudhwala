import { Inject, Injectable } from '@nestjs/common';
import type { Request, Response } from 'express';
import { Clock } from '../common/clock.js';
import { newId } from '../common/crypto.js';
import { AppError } from '../common/errors.js';
import { ENV, type Env } from '../config/env.js';
import { PrismaService } from '../prisma/prisma.service.js';
import { AuditService } from '../audit/audit.service.js';
import { LIMITS, RateLimitService } from '../rate-limit/rate-limit.service.js';
import { SessionService } from './session.service.js';

/**
 * Guest checkout (GUEST_CHECKOUT=true): starts a customer session for a brand-new, unverified customer with no phone
 * on the account. Each guest is a separate user, so a guest can never see or touch another customer's orders. The
 * contact name, phone and address live on the order itself, and staff confirm the order by phone.
 */
@Injectable()
export class GuestAuthService {
  constructor(
    @Inject(PrismaService) private readonly prisma: PrismaService,
    @Inject(Clock) private readonly clock: Clock,
    @Inject(ENV) private readonly env: Env,
    @Inject(SessionService) private readonly sessions: SessionService,
    @Inject(AuditService) private readonly audit: AuditService,
    @Inject(RateLimitService) private readonly limits: RateLimitService,
  ) {}

  async start(req: Request, res: Response): Promise<{ userId: string }> {
    if (this.env.GUEST_CHECKOUT !== 'true') throw new AppError('NOT_FOUND', 'Not found');
    await this.limits.hit(LIMITS.guestPerIp, req.ip);
    const now = this.clock.now();
    return this.prisma.$transaction(async (tx) => {
      const user = await tx.user.create({ data: { id: newId(), kind: 'CUSTOMER', lastLoginAt: now } });
      await this.sessions.create(user.id, 'CUSTOMER', req, res, {}, tx);
      await this.audit.record({ action: 'customer.guest_started', entityType: 'user', entityId: user.id }, { userId: user.id, actorRole: 'CUSTOMER' }, req, tx);
      return { userId: user.id };
    });
  }
}
